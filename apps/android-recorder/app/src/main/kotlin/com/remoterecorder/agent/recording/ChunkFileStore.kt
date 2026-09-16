package com.remoterecorder.agent.recording

import android.content.Context
import java.io.File

/**
 * Manages the temporary on-device location for in-flight audio chunks.
 * This directory is the *only* place audio ever touches disk on the
 * device — see the README's data-loss trade-off section for why: chunks
 * are deleted as soon as the server acknowledges the upload, and the full
 * recording is never assembled or kept locally.
 */
object ChunkFileStore {

    private const val DIR_NAME = "pending_chunks"

    private fun directory(context: Context): File {
        val dir = File(context.cacheDir, DIR_NAME)
        if (!dir.exists()) dir.mkdirs()
        return dir
    }

    fun newChunkFile(context: Context, chunkNumber: Int): File {
        return File(directory(context), fileName(chunkNumber))
    }

    fun fileFor(context: Context, chunkNumber: Int): File {
        return File(directory(context), fileName(chunkNumber))
    }

    private fun fileName(chunkNumber: Int): String = "chunk-%05d.aac".format(chunkNumber)

    /** Deletes a chunk's temp file once the server has acknowledged the upload. */
    fun delete(file: File) {
        if (file.exists()) file.delete()
    }

    /** Best-effort cleanup of any orphaned chunk files left by a previous crash. */
    fun clearAll(context: Context) {
        directory(context).listFiles()?.forEach { it.delete() }
    }

    fun pendingChunkCount(context: Context): Int = directory(context).listFiles()?.size ?: 0
}
