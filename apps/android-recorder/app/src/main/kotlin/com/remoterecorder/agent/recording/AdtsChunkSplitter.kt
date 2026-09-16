package com.remoterecorder.agent.recording

import java.io.File
import java.io.OutputStream
import java.io.RandomAccessFile
import java.security.MessageDigest

/**
 * Splits one large AAC-ADTS file (the whole-session recording produced by
 * [ChunkingAudioRecorder]) into upload-sized part files, cut only at ADTS
 * frame boundaries. ADTS-AAC is self-framing — every frame carries its own
 * 7-byte sync header with its length — so a cut between two frames yields
 * two byte sequences that are each still valid, independently playable
 * ADTS streams, with no re-encoding needed. This is the same
 * byte-concatenation property the server's finalization step already
 * relies on to merge chunks back together.
 *
 * Takes plain [File]s rather than an Android [android.content.Context] so
 * the frame-parsing logic — the part worth unit-testing — has no Android
 * runtime dependency; ChunkFileStore.newChunkFile is what the caller uses
 * to name the part files it hands in via [partFileFor].
 */
object AdtsChunkSplitter {

    data class Part(val file: File, val partNumber: Int, val checksum: String)

    /**
     * @param targetPartBytes Split near this size, at the first frame
     * boundary at or after it — never mid-frame, so the exact part size
     * varies slightly with where frame boundaries actually fall.
     * @param partFileFor Given a 1-based part number, returns the File to
     * write that part into (the caller owns naming/location — see
     * ChunkFileStore.newChunkFile).
     */
    fun split(source: File, targetPartBytes: Long, partFileFor: (partNumber: Int) -> File): List<Part> {
        val parts = mutableListOf<Part>()
        var partNumber = 0
        var partOut: OutputStream? = null
        var partFile: File? = null
        var partDigest: MessageDigest? = null
        var partBytesWritten = 0L

        fun openNextPart() {
            partNumber += 1
            val file = partFileFor(partNumber)
            partFile = file
            partOut = file.outputStream()
            partDigest = MessageDigest.getInstance("SHA-256")
            partBytesWritten = 0L
        }

        fun closeCurrentPart() {
            val out = partOut ?: return
            out.flush()
            out.close()
            val file = partFile!!
            val digest = partDigest!!
            if (partBytesWritten > 0) {
                parts.add(Part(file, partNumber, digest.digest().joinToString("") { "%02x".format(it) }))
            } else {
                runCatching { file.delete() }
                partNumber -= 1
            }
            partOut = null
            partFile = null
            partDigest = null
        }

        fun writeToCurrentPart(buffer: ByteArray, offset: Int, length: Int) {
            partOut!!.write(buffer, offset, length)
            partDigest!!.update(buffer, offset, length)
            partBytesWritten += length
        }

        openNextPart()

        RandomAccessFile(source, "r").use { raf ->
            val fileLength = raf.length()
            var position = 0L
            val header = ByteArray(7)

            while (position < fileLength) {
                raf.seek(position)
                val headerRead = raf.read(header)
                if (headerRead < 7 || !isAdtsSync(header)) {
                    // Not a valid frame start (truncated tail, or unexpected
                    // data) — flush the remainder as-is rather than losing it;
                    // downstream playback of a slightly malformed tail is a
                    // better outcome than silently dropping audio.
                    raf.seek(position)
                    val remaining = ByteArray((fileLength - position).toInt())
                    raf.readFully(remaining)
                    writeToCurrentPart(remaining, 0, remaining.size)
                    break
                }

                val frameLength = adtsFrameLength(header)
                if (frameLength <= 0 || position + frameLength > fileLength) {
                    // Malformed/truncated final frame — same reasoning as above.
                    raf.seek(position)
                    val remaining = ByteArray((fileLength - position).toInt())
                    raf.readFully(remaining)
                    writeToCurrentPart(remaining, 0, remaining.size)
                    break
                }

                raf.seek(position)
                val frame = ByteArray(frameLength)
                raf.readFully(frame)
                writeToCurrentPart(frame, 0, frame.size)
                position += frameLength

                if (partBytesWritten >= targetPartBytes && position < fileLength) {
                    closeCurrentPart()
                    openNextPart()
                }
            }
        }

        closeCurrentPart()

        return parts
    }

    private fun isAdtsSync(header: ByteArray): Boolean {
        // First 12 bits all set is the ADTS sync word (0xFFF).
        return (header[0].toInt() and 0xFF) == 0xFF && (header[1].toInt() and 0xF0) == 0xF0
    }

    /** ADTS frame length is a 13-bit field spanning bytes 3-5 of the header. */
    private fun adtsFrameLength(header: ByteArray): Int {
        return ((header[3].toInt() and 0x03) shl 11) or
            ((header[4].toInt() and 0xFF) shl 3) or
            ((header[5].toInt() and 0xFF) shr 5)
    }
}
