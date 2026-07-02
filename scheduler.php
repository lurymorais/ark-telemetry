<?php
/**
 * Progressive Round Robin Scheduler - v3.1.0
 * 
 * Distributes statistics collection across the month using a 28-day window
 * with progressive slot allocation (days → hours → minutes → cycles)
 * 
 * @package ARKTelemetry
 * @version 3.1.0
 */

class ProgressiveRoundRobinScheduler
{
    private $maxDays = 28;
    private $maxHours = 24;
    private $maxMinutes = 60;
    
    /**
     * Calculate the next available time slot
     * 
     * @param int $currentSlots Number of existing registrations
     * @return array {day, hour, minute, second, cycle, timestamp, human}
     */
    public function calculateNextSlot($currentSlots)
    {
        $maxSlots = $this->maxDays * $this->maxHours * $this->maxMinutes; // 40,320
        
        $position = $currentSlots % $maxSlots;
        $cycle = (int) floor($currentSlots / $maxSlots);
        
        // Extract components using division
        $minute = $position % $this->maxMinutes;
        $position = (int) floor($position / $this->maxMinutes);
        $hour = $position % $this->maxHours;
        $position = (int) floor($position / $this->maxHours);
        $day = $position % $this->maxDays + 1; // 1-based
        
        // Offset increases with each cycle to avoid exact conflicts
        $offsetSeconds = $cycle % 60;
        
        return [
            'day' => $day,
            'hour' => $hour,
            'minute' => $minute,
            'second' => $offsetSeconds,
            'cycle' => $cycle,
            'timestamp' => $this->calculateTimestamp($day, $hour, $minute, $offsetSeconds),
            'human' => date('Y-m-d H:i:s', $this->calculateTimestamp($day, $hour, $minute, $offsetSeconds))
        ];
    }
    
    /**
     * Calculate Unix timestamp for the next occurrence
     * 
     * @param int $day Day of month (1-28)
     * @param int $hour Hour (0-23)
     * @param int $minute Minute (0-59)
     * @param int $second Second (0-59)
     * @return int Unix timestamp
     */
    private function calculateTimestamp($day, $hour, $minute, $second)
    {
        $now = time();
        $currentDay = (int) date('j');
        $currentMonth = (int) date('m');
        $currentYear = (int) date('Y');
        
        // Determine target month
        if ($day <= $currentDay) {
            // Next month
            $targetMonth = $currentMonth + 1;
            $targetYear = $currentYear;
            if ($targetMonth > 12) {
                $targetMonth = 1;
                $targetYear++;
            }
        } else {
            // Current month
            $targetMonth = $currentMonth;
            $targetYear = $currentYear;
        }
        
        $timestamp = mktime($hour, $minute, $second, $targetMonth, $day, $targetYear);
        
        // If timestamp is in the past, add one month
        if ($timestamp < $now) {
            $timestamp = strtotime('+1 month', $timestamp);
        }
        
        return $timestamp;
    }
    
    /**
     * Check if today is a valid collection day (1-28 only)
     * 
     * @param int $day Current day of month
     * @return bool
     */
    public function isValidCollectionDay($day)
    {
        return $day >= 1 && $day <= $this->maxDays;
    }
    
    /**
     * Get journals scheduled for today
     * 
     * @param PDO $pdo Database connection
     * @return array List of journals with their NAAN and collection time
     */
    public function getJournalsForToday($pdo)
    {
        $today = (int) date('j');
        $currentMonth = (int) date('m');
        $currentYear = (int) date('Y');
        
        // Only process days 1-28
        if (!$this->isValidCollectionDay($today)) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT naan, next_collection 
            FROM ark_statistics 
            WHERE DAY(next_collection) = ?
              AND MONTH(next_collection) = ?
              AND YEAR(next_collection) = ?
            ORDER BY next_collection ASC
        ");
        $stmt->execute([$today, $currentMonth, $currentYear]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}