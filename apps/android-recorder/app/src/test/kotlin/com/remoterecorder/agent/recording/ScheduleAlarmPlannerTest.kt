package com.remoterecorder.agent.recording

import com.remoterecorder.agent.model.DeviceSchedule
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.time.ZoneId
import java.time.ZonedDateTime

class ScheduleAlarmPlannerTest {

    private val jakarta = ZoneId.of("Asia/Jakarta")

    private fun schedule(id: Long, dayOfWeek: Int, timeOfDay: String, preset: String = "MEDIUM", durationMinutes: Int = 30) =
        DeviceSchedule(id = id, dayOfWeek = dayOfWeek, timeOfDay = timeOfDay, preset = preset, durationMinutes = durationMinutes)

    @Test
    fun `given a schedule later today, next occurrence is today at that time`() {
        // Wednesday 2026-01-07 10:00 Jakarta; dayOfWeek 3 = Wednesday.
        val now = ZonedDateTime.of(2026, 1, 7, 10, 0, 0, 0, jakarta)
        val schedules = listOf(schedule(1, dayOfWeek = 3, timeOfDay = "14:30:00"))

        val next = ScheduleAlarmPlanner.nextOccurrence(schedules, now)

        requireNotNull(next)
        val expected = ZonedDateTime.of(2026, 1, 7, 14, 30, 0, 0, jakarta)
        assertEquals(expected.toInstant().toEpochMilli(), next.triggerAtEpochMillis)
        assertEquals(1L, next.schedule.id)
    }

    @Test
    fun `given a schedule earlier today (already passed), next occurrence rolls to next week`() {
        // Wednesday 2026-01-07 10:00 Jakarta; schedule was at 08:00 Wednesday, already passed.
        val now = ZonedDateTime.of(2026, 1, 7, 10, 0, 0, 0, jakarta)
        val schedules = listOf(schedule(1, dayOfWeek = 3, timeOfDay = "08:00:00"))

        val next = ScheduleAlarmPlanner.nextOccurrence(schedules, now)

        requireNotNull(next)
        val expected = ZonedDateTime.of(2026, 1, 14, 8, 0, 0, 0, jakarta) // next Wednesday
        assertEquals(expected.toInstant().toEpochMilli(), next.triggerAtEpochMillis)
    }

    @Test
    fun `given multiple schedules, next occurrence picks the earliest across all of them`() {
        val now = ZonedDateTime.of(2026, 1, 7, 10, 0, 0, 0, jakarta) // Wednesday
        val schedules = listOf(
            schedule(1, dayOfWeek = 3, timeOfDay = "20:00:00"), // today, later
            schedule(2, dayOfWeek = 3, timeOfDay = "12:00:00"), // today, earliest
            schedule(3, dayOfWeek = 4, timeOfDay = "01:00:00"), // tomorrow
        )

        val next = ScheduleAlarmPlanner.nextOccurrence(schedules, now)

        requireNotNull(next)
        assertEquals(2L, next.schedule.id)
        val expected = ZonedDateTime.of(2026, 1, 7, 12, 0, 0, 0, jakarta)
        assertEquals(expected.toInstant().toEpochMilli(), next.triggerAtEpochMillis)
    }

    @Test
    fun `given an empty schedule list, next occurrence is null`() {
        val now = ZonedDateTime.of(2026, 1, 7, 10, 0, 0, 0, jakarta)

        val next = ScheduleAlarmPlanner.nextOccurrence(emptyList(), now)

        assertNull(next)
    }

    @Test
    fun `given a schedule exactly at now, next occurrence rolls forward (not immediate re-fire)`() {
        val now = ZonedDateTime.of(2026, 1, 7, 14, 30, 0, 0, jakarta)
        val schedules = listOf(schedule(1, dayOfWeek = 3, timeOfDay = "14:30:00"))

        val next = ScheduleAlarmPlanner.nextOccurrence(schedules, now)

        requireNotNull(next)
        val expected = ZonedDateTime.of(2026, 1, 14, 14, 30, 0, 0, jakarta)
        assertEquals(expected.toInstant().toEpochMilli(), next.triggerAtEpochMillis)
    }

    @Test
    fun `time zone correctness a schedule at 23_30 Asia_Jakarta relative to a UTC now resolves correctly`() {
        // Jakarta is UTC+7 (no DST). 2026-01-07 23:30 Jakarta == 2026-01-07 16:30 UTC.
        // Pass "now" as a UTC ZonedDateTime, earlier in Jakarta wall-clock terms.
        val nowUtc = ZonedDateTime.of(2026, 1, 7, 10, 0, 0, 0, ZoneId.of("UTC")) // == 17:00 Jakarta
        val schedules = listOf(schedule(1, dayOfWeek = 3, timeOfDay = "23:30:00")) // Wednesday 23:30 Jakarta

        val next = ScheduleAlarmPlanner.nextOccurrence(schedules, nowUtc)

        requireNotNull(next)
        val expected = ZonedDateTime.of(2026, 1, 7, 23, 30, 0, 0, jakarta)
        assertEquals(expected.toInstant().toEpochMilli(), next.triggerAtEpochMillis)
    }
}
