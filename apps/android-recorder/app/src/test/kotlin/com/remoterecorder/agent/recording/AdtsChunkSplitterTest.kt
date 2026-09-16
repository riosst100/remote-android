package com.remoterecorder.agent.recording

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File
import java.security.MessageDigest

class AdtsChunkSplitterTest {

    @get:Rule
    val tempFolder = TemporaryFolder()

    /** Builds one synthetic ADTS frame: a valid 7-byte header (frame length = payload.size + 7) followed by payload bytes. */
    private fun adtsFrame(payload: ByteArray): ByteArray {
        val frameLength = payload.size + 7
        val header = ByteArray(7)
        header[0] = 0xFF.toByte()
        header[1] = 0xF9.toByte()
        header[2] = 0x50.toByte() // arbitrary valid profile/sampling/channel bits
        header[3] = (0x40 or (frameLength shr 11)).toByte()
        header[4] = ((frameLength shr 3) and 0xFF).toByte()
        header[5] = (((frameLength and 7) shl 5) or 0x1F).toByte()
        header[6] = 0xFC.toByte()
        return header + payload
    }

    private fun writeSyntheticFile(frameCount: Int, payloadSize: Int): File {
        val file = tempFolder.newFile("recording.aac")
        file.outputStream().use { out ->
            repeat(frameCount) { i ->
                val payload = ByteArray(payloadSize) { (i and 0xFF).toByte() }
                out.write(adtsFrame(payload))
            }
        }
        return file
    }

    private fun sha256(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buffer = ByteArray(8192)
            var read: Int
            while (input.read(buffer).also { read = it } != -1) digest.update(buffer, 0, read)
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }

    @Test
    fun `small recording under the part size produces exactly one part`() {
        val source = writeSyntheticFile(frameCount = 5, payloadSize = 100)

        val parts = AdtsChunkSplitter.split(source, targetPartBytes = 1_000_000) { n ->
            tempFolder.newFile("part-$n.aac")
        }

        assertEquals(1, parts.size)
        assertEquals(1, parts[0].partNumber)
        assertArrayEquals(source.readBytes(), parts[0].file.readBytes())
    }

    @Test
    fun `large recording splits into multiple parts at frame boundaries`() {
        // 50 frames of 107 bytes each (100 payload + 7 header) = 5350 bytes total.
        // Target 1000 bytes/part should yield several parts.
        val source = writeSyntheticFile(frameCount = 50, payloadSize = 100)

        val parts = AdtsChunkSplitter.split(source, targetPartBytes = 1000) { n ->
            File(tempFolder.root, "part-%03d.aac".format(n))
        }

        assertTrue("expected multiple parts, got ${parts.size}", parts.size > 1)

        // Part numbers are contiguous starting at 1.
        parts.forEachIndexed { index, part -> assertEquals(index + 1, part.partNumber) }

        // Every part boundary lands exactly on a frame start: reassembling
        // all parts byte-for-byte must reproduce the original file exactly.
        val reassembled = parts.fold(ByteArray(0)) { acc, part -> acc + part.file.readBytes() }
        assertArrayEquals(source.readBytes(), reassembled)

        // Each part's on-disk checksum matches what the splitter reported.
        parts.forEach { part -> assertEquals(sha256(part.file), part.checksum) }
    }

    @Test
    fun `each split part is itself a valid sequence of complete ADTS frames`() {
        val source = writeSyntheticFile(frameCount = 30, payloadSize = 50)

        val parts = AdtsChunkSplitter.split(source, targetPartBytes = 500) { n ->
            File(tempFolder.root, "part-%03d.aac".format(n))
        }

        for (part in parts) {
            val bytes = part.file.readBytes()
            var position = 0
            while (position < bytes.size) {
                assertEquals(0xFF, bytes[position].toInt() and 0xFF)
                assertEquals(0xF0, bytes[position + 1].toInt() and 0xF0)
                val frameLength = ((bytes[position + 3].toInt() and 0x03) shl 11) or
                    ((bytes[position + 4].toInt() and 0xFF) shl 3) or
                    ((bytes[position + 5].toInt() and 0xFF) shr 5)
                assertTrue("frame must fit within the part", position + frameLength <= bytes.size)
                position += frameLength
            }
            assertEquals("no trailing partial bytes", bytes.size, position)
        }
    }

    @Test
    fun `empty source file produces no parts`() {
        val source = tempFolder.newFile("empty.aac")

        val parts = AdtsChunkSplitter.split(source, targetPartBytes = 1000) { n ->
            File(tempFolder.root, "part-$n.aac")
        }

        assertEquals(0, parts.size)
    }
}
