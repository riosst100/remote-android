package com.remoterecorder.agent.recording

import com.remoterecorder.agent.model.DeviceSchedule
import java.time.DayOfWeek
import java.time.LocalTime
import java.time.ZonedDateTime

/**
 * Pure function: given the locally synced schedules and the current moment
 * (any zone — internally normalized to Asia/Jakarta, the schedule's own
 * wall-clock zone), computes the next schedule occurrence to arm an alarm
 * for. Extracted from `ScheduleAlarmScheduler` specifically so it's
 * unit-testable without Robolectric/instrumentation — no Android framework
 * dependency, only `java.time`.
 */
object ScheduleAlarmPlanner {

    data class NextOccurrence(val schedule: DeviceSchedule, val triggerAtEpochMillis: Long)

    /**
     * `dayOfWeek` on [DeviceSchedule] is 0=Sunday..6=Saturday (matches
     * Carbon::dayOfWeek server-side), while `java.time.DayOfWeek` is
     * 1=Monday..7=Sunday. This table converts the former into the latter.
     */
    private val ISO_DAY_OF_WEEK = arrayOf(
        DayOfWeek.SUNDAY, // 0
        DayOfWeek.MONDAY, // 1
        DayOfWeek.TUESDAY, // 2
        DayOfWeek.WEDNESDAY, // 3
        DayOfWeek.THURSDAY, // 4
        DayOfWeek.FRIDAY, // 5
        DayOfWeek.SATURDAY, // 6
    )

    /**
     * Returns the earliest occurrence, strictly after [now], across all
     * [schedules]. `now` is converted to Asia/Jakarta internally, so callers
     * may pass a `ZonedDateTime` in any zone (e.g. the device's own local
     * zone, or UTC) and still get a correct result.
     */
    fun nextOccurrence(schedules: List<DeviceSchedule>, now: ZonedDateTime): NextOccurrence? {
        if (schedules.isEmpty()) return null

        val jakartaNow = now.withZoneSameInstant(ScheduleTimeZone)

        return schedules
            .mapNotNull { schedule -> nextOccurrenceFor(schedule, jakartaNow) }
            .minByOrNull { it.triggerAtEpochMillis }
    }

    private fun nextOccurrenceFor(schedule: DeviceSchedule, jakartaNow: ZonedDateTime): NextOccurrence? {
        val timeOfDay = runCatching { LocalTime.parse(schedule.timeOfDay) }.getOrNull() ?: return null
        val targetDay = schedule.dayOfWeek.let { if (it in 0..6) ISO_DAY_OF_WEEK[it] else null } ?: return null

        var candidate = jakartaNow
            .with(java.time.temporal.TemporalAdjusters.nextOrSame(targetDay))
            .withHour(timeOfDay.hour)
            .withMinute(timeOfDay.minute)
            .withSecond(timeOfDay.second)
            .withNano(0)

        // Strictly after `now` — an occurrence exactly at `now` (or already
        // passed) rolls forward a full week, never fires immediately. This
        // matters most for ScheduleAlarmReceiver re-arming the *next*
        // occurrence right after firing the current one.
        if (!candidate.isAfter(jakartaNow)) {
            candidate = candidate.plusWeeks(1)
        }

        return NextOccurrence(schedule, candidate.toInstant().toEpochMilli())
    }

    val ScheduleTimeZone: java.time.ZoneId = java.time.ZoneId.of("Asia/Jakarta")
}
