    <?php

    date_default_timezone_set('Africa/Cairo');

    function envValue($key, $default)
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }

    $CONFIG = [
        'EMAIL'       => envValue('TRACKER_EMAIL', 'a.yahya@domapphub.com'),
        'PASSWORD'    => envValue('TRACKER_PASSWORD', '75#Yehia#98'),

        'TG_BOT_TOKEN' => envValue('TG_BOT_TOKEN', '8967817334:AAHeZzdeLrubTte8mpv3DF_0tUkKD2_-OZg'),
        'TG_CHAT_ID'   => envValue('TG_CHAT_ID', '1050545915'),


        'TARGET_MIN'  => 480,
        'WEEKLY_TARGET_MIN' => 2400,
        'MONTHLY_TARGET_MIN' => null,
        'LONG_RUNNING_TIMER_ALERT_MIN' => (int)envValue('LONG_RUNNING_TIMER_ALERT_MIN', 180),
        'AUTO_SWITCH_BEFORE_MIN' => (int)envValue('AUTO_SWITCH_BEFORE_MIN', 5),
        'STOP_CONFIRMATION_MISSES' => max(1, (int)envValue('STOP_CONFIRMATION_MISSES', 2)),

        'WA_APP_TITLE' => 'tracker',
        'VERBOSE_LOG' => false,
    ];

    if (empty($CONFIG['EMAIL']) || empty($CONFIG['PASSWORD']) || empty($CONFIG['TG_BOT_TOKEN'])) {
        die("❌ خطأ: متغيرات البيئة (Credentials) غير موجودة. يرجى تمرير TRACKER_EMAIL و TRACKER_PASSWORD و TG_BOT_TOKEN.\n");
    }

    // COOKIE_FILE and STATE_FILE are now set per-user in the main loop
    $LOCK_FILE          = __DIR__ . '/tracker_yehia.lock';

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    function pruneOldState(&$state)
    {
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));

        if (isset($state['dailyStats']) && is_array($state['dailyStats'])) {
            foreach ($state['dailyStats'] as $date => $data) {
                if ($date < $thirtyDaysAgo) unset($state['dailyStats'][$date]);
            }
        }

        $sessionKeys = ['timerStartedSentSessions', 'longRunningTimerSentSessions', 'countedSessions'];
        foreach ($sessionKeys as $key) {
            if (isset($state[$key]) && is_array($state[$key])) {
                foreach ($state[$key] as $sessionKey => $val) {
                    $parts = explode('|', $sessionKey);
                    $sessionDate = $parts[1] ?? '';
                    if ($sessionDate && $sessionDate < $thirtyDaysAgo) {
                        unset($state[$key][$sessionKey]);
                    }
                }
            }
        }
    }

    function debugLog($message)
    {
        global $CONFIG;

        if (empty($CONFIG['VERBOSE_LOG'])) {
            return;
        }

        $line = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;

        echo $line;
    }

    function summaryLog($message)
    {
        $line = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;

        echo $line;
    }

    function desktopNotify($title, $message)
    {
        if (isDayOff()) return;

        if (!commandExists('notify-send')) {
            debugLog("إشعار الديسكتوب متبعتش: notify-send مش موجود");
            return;
        }

        $title   = escapeshellarg($title);
        $message = escapeshellarg($message);

        // إشعار
        exec("notify-send {$title} {$message} > /dev/null 2>&1");

        // صوت
        if (commandExists('paplay')) {
            exec("paplay /usr/share/sounds/freedesktop/stereo/message.oga > /dev/null 2>&1 &");
        }
    }

    function commandExists($command)
    {
        $command = escapeshellarg($command);
        $result = trim((string)shell_exec("command -v {$command} 2>/dev/null"));

        return $result !== '';
    }

    function todayDate()
    {
        return date('Y-m-d');
    }

    function weekKeyForDate($date)
    {
        $timestamp = strtotime($date);

        if (!$timestamp) {
            $timestamp = time();
        }

        $daysSinceSunday = (int)date('w', $timestamp);
        $weekStart = strtotime("-{$daysSinceSunday} days", $timestamp);

        return date('Y-m-d', $weekStart);
    }

    function isWorkWeekDate($date)
    {
        $timestamp = strtotime($date);

        if (!$timestamp) {
            return false;
        }

        return (int)date('w', $timestamp) <= 4;
    }

    function isThursday($date)
    {
        $timestamp = strtotime($date);

        return $timestamp && (int)date('N', $timestamp) === 4;
    }

    function monthCycleKeyForDate($date)
    {
        [$start] = getMonthCycleRange($date);

        return $start;
    }

    function getMonthCycleRange($date)
    {
        $timestamp = strtotime($date) ?: time();
        $day = (int)date('j', $timestamp);

        if ($day >= 26) {
            $start = date('Y-m-26', $timestamp);
            $end = date('Y-m-25', strtotime($start . ' +1 month'));
        } else {
            $end = date('Y-m-25', $timestamp);
            $start = date('Y-m-26', strtotime($end . ' -1 month'));
        }

        return [$start, $end];
    }

    function nowTime()
    {
        return date('H:i:s');
    }

    function convertToMinutes($timeStr)
    {
        if (!$timeStr || strpos($timeStr, ':') === false) {
            return 0;
        }

        $timeStr = preg_replace('/\s*:\s*/', ':', trim($timeStr));
        $parts = explode(':', $timeStr);

        // يدعم HH:MM و HH:MM:SS
        if (count($parts) >= 3) {
            return ((int)$parts[0] * 60) + (int)$parts[1];
        }

        if (count($parts) === 2) {
            return ((int)$parts[0] * 60) + (int)$parts[1];
        }

        return 0;
    }

    function plainText($html)
    {
        $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    function findLabeledMinutes($text, $labels)
    {
        foreach ($labels as $label) {
            $pattern = '/' . preg_quote($label, '/') . '\s*:?\s*([0-9]{1,3}\s*:\s*[0-9]{2}(?:\s*:\s*[0-9]{2})?)/i';

            if (preg_match($pattern, $text, $matches)) {
                return convertToMinutes($matches[1]);
            }
        }

        return 0;
    }

    function convertDurationToMinutes($timeStr)
    {
        $clean = trim(strip_tags((string)$timeStr));
        $clean = preg_replace('/\s+/', '', $clean);

        if ($clean === '' || strpos($clean, ':') === false) {
            return 0;
        }

        $parts = array_map('intval', explode(':', $clean));

        if (count($parts) >= 3) {
            return ($parts[0] * 60) + $parts[1] + ($parts[2] >= 30 ? 1 : 0);
        }

        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        return 0;
    }

    function formatMinutes($minutes)
    {
        $minutes = max(0, (int)$minutes);

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return sprintf('%02d:%02d', $h, $m);
    }

    // للوجات: يطبع الدقائق والصيغة HH:MM مع بعض
    function fmtLog($minutes)
    {
        return $minutes . ' min (' . formatMinutes($minutes) . ')';
    }

    function ensureDailyStats($state, $date)
    {
        if (!isset($state['dailyStats']) || !is_array($state['dailyStats'])) {
            $state['dailyStats'] = [];
        }

        if (!isset($state['dailyStats'][$date]) || !is_array($state['dailyStats'][$date])) {
            $state['dailyStats'][$date] = [
                'week' => weekKeyForDate($date),
                'rawMin' => 0,
                'countedMin' => 0,
                'sessions' => [],
            ];
        }

        if (!isset($state['dailyStats'][$date]['sessions']) || !is_array($state['dailyStats'][$date]['sessions'])) {
            $state['dailyStats'][$date]['sessions'] = [];
        }

        $state['dailyStats'][$date]['week'] = weekKeyForDate($date);
        $state['dailyStats'][$date]['rawMin'] = (int)($state['dailyStats'][$date]['rawMin'] ?? 0);
        $state['dailyStats'][$date]['countedMin'] = (int)($state['dailyStats'][$date]['countedMin'] ?? 0);

        return $state;
    }

    function getTaskRawMinutesFromState($state, $taskId)
    {
        $total = 0;

        foreach (($state['dailyStats'] ?? []) as $dayStats) {
            foreach (($dayStats['sessions'] ?? []) as $session) {
                if ((string)($session['taskId'] ?? '') === (string)$taskId) {
                    $total += (int)($session['rawMin'] ?? 0);
                }
            }
        }

        return $total;
    }

    function getTaskRawMinutesFromRows($rows, $taskId)
    {
        $total = 0;

        foreach ($rows as $row) {
            if ((string)($row['taskId'] ?? '') === (string)$taskId) {
                $total += (int)($row['rawMin'] ?? 0);
            }
        }

        return $total;
    }

    function logErpWorkSummary($cookieFile, $date, $weeklyTargetMin)
    {
        $effectiveWeeklyTarget = getWeeklyTargetMinutes($date, $weeklyTargetMin);

        $todayRows = fetchTimesheetsForRange($cookieFile, $date, $date);
        $todaySummary = calculateValidWeeklyMinutesFromTimesheets($todayRows);

        $rows = fetchTimesheetsForWorkWeek($cookieFile, $date);
        $summary = calculateValidWeeklyMinutesFromTimesheets($rows);
        $weekRemainingMin = max(0, (int)$effectiveWeeklyTarget - (int)$summary['validMin']);

        echo "📊 ملخص النهارده:" . PHP_EOL;
        echo "▫️ اشتغلت: " . formatMinutes($todaySummary['rawMin']) . PHP_EOL;
        echo "▫️ مطابق للشروط: " . formatMinutes($todaySummary['validMin']) . PHP_EOL;
        echo PHP_EOL;
        echo "📅 ملخص الأسبوع:" . PHP_EOL;
        echo "▫️ اشتغلت: " . formatMinutes($summary['rawMin']) . PHP_EOL;
        echo "▫️ مطابق للشروط: " . formatMinutes($summary['validMin']) . " من " . formatMinutes($effectiveWeeklyTarget) . PHP_EOL;
        echo "▫️ فاضل على هدف الأسبوع: " . formatMinutes($weekRemainingMin) . PHP_EOL;
    }

    function formatPeriodReport($title, $startDate, $endDate, $summary, $targetMin = null, $includeTasks = true)
    {
        $lines = [];
        $lines[] = "📈 *{$title}* (من {$startDate} لحد {$endDate})";
        $lines[] = "";
        $lines[] = "⏱️ *إجمالي الشغل:* " . formatMinutes($summary['rawMin']);

        $validLine = "✅ *المحسوب:* " . formatMinutes($summary['validMin']);
        if ($targetMin !== null) {
            $validLine .= " (من " . formatMinutes($targetMin) . ")";
            $lines[] = $validLine;
            $lines[] = "⏳ *فاضل:* " . formatMinutes(max(0, (int)$targetMin - (int)$summary['validMin']));
        } else {
            $lines[] = $validLine;
        }

        if ((int)$summary['ignoredMin'] > 0) {
            $lines[] = "❌ *مستبعد (لم يحسب):* " . formatMinutes($summary['ignoredMin']);
        }

        $lines[] = "";
        $lines[] = "📅 *تفاصيل الأيام:*";

        foreach ($summary['dayRaw'] as $day => $rawMin) {
            $lines[] = "▫️ {$day}: " . formatMinutes($rawMin) .
                " (محسوب: " . formatMinutes($summary['dayCounted'][$day] ?? 0) . ")";
        }

        if ($includeTasks) {
            $lines[] = "";
            $lines[] = "📌 *تفاصيل التاسكات:*";

            foreach ($summary['taskSummary'] as $taskId => $task) {
                $ignoredMin = max(0, (int)$task['rawMin'] - (int)$task['validMin']);
                $line = "▫️ #{$taskId} {$task['title']}: " . formatMinutes($task['rawMin']) .
                    " (محسوب: " . formatMinutes($task['validMin']) . ")";

                if ($ignoredMin > 0) {
                    $line .= " / زيادة " . formatMinutes($ignoredMin);
                }

                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    function printDetailsReport($cookieFile, $date, $weeklyTargetMin)
    {
        $effectiveWeeklyTarget = getWeeklyTargetMinutes($date, $weeklyTargetMin);
        [$weekStart, $weekEnd] = getWorkWeekRange($date);
        $weekRows = fetchTimesheetsForRange($cookieFile, $weekStart, $weekEnd);
        $weekSummary = calculateValidWeeklyMinutesFromTimesheets($weekRows);

        summaryLog(formatPeriodReport("تفاصيل الأسبوع", $weekStart, $weekEnd, $weekSummary, $effectiveWeeklyTarget));
    }

    function buildFullReportMessage($cookieFile, $date, $config, $title = "تقرير مفصل")
    {
        [$weekStart, $weekEnd] = getWorkWeekRange($date);
        [$monthStart, $monthEnd] = getMonthCycleRange($date);

        $effectiveWeeklyTarget = getWeeklyTargetMinutes($date, $config['WEEKLY_TARGET_MIN']);

        $weekSummary = calculateValidWeeklyMinutesFromTimesheets(
            fetchTimesheetsForRange($cookieFile, $weekStart, $weekEnd)
        );
        $monthSummary = calculateValidWeeklyMinutesFromTimesheets(
            fetchTimesheetsForRange($cookieFile, $monthStart, $monthEnd)
        );

        return "*{$title}*\n\n" .
            formatPeriodReport("الأسبوع", $weekStart, $weekEnd, $weekSummary, $effectiveWeeklyTarget) .
            "\n\n" .
            formatPeriodReport("الشهر", $monthStart, $monthEnd, $monthSummary, $config['MONTHLY_TARGET_MIN']);
    }

    function maybeSendMonthEndReport($state, $cookieFile, $config, $date)
    {
        if ((int)date('j', strtotime($date)) !== 25) {
            return $state;
        }

        $currentHM = date('H:i');
        if ($currentHM < '22:00' || $currentHM > '22:09') {
            return $state;
        }

        $reportKey = $date;

        if (($state['lastMonthEndReportSent'] ?? '') === $reportKey) {
            return $state;
        }

        [$monthStart, $monthEnd] = getMonthCycleRange($date);

        $monthRows = fetchTimesheetsForRange($cookieFile, $monthStart, $monthEnd);
        $monthSummary = calculateValidWeeklyMinutesFromTimesheets($monthRows);

        $report = formatPeriodReport("تقفيل الشهر", $monthStart, $monthEnd, $monthSummary, $config['MONTHLY_TARGET_MIN'], false);

        summaryLog("");
        summaryLog($report);

        if (sendTelegram($report, $config)) {
            $state['lastMonthEndReportSent'] = $reportKey;
        } else {
            summaryLog("تقرير قفل الشهر متبعتش، هحاول تاني في التشغيل الجاي");
        }

        return $state;
    }

    function maybeSendThursdayReport($state, $cookieFile, $config, $date)
    {
        if (!isThursday($date)) {
            return $state;
        }

        $reportKey = $date;

        if (($state['lastThursdayReportSent'] ?? '') === $reportKey) {
            return $state;
        }

        $report = buildFullReportMessage($cookieFile, $date, $config, "تقرير الخميس");

        summaryLog("");
        summaryLog($report);

        if (sendTelegram($report, $config)) {
            $state['lastThursdayReportSent'] = $reportKey;
        } else {
            summaryLog("تقرير الخميس متبعتش، هحاول تاني في التشغيل الجاي");
        }

        return $state;
    }



    function buildSessionKey($taskId, $sessionDate, $startedAt)
    {
        return $taskId . '|' . $sessionDate . '|' . ($startedAt ?: 'unknown-start');
    }

    function confirmTimerStopMiss(&$state, $sessionKey, $requiredMisses)
    {
        $requiredMisses = max(1, (int)$requiredMisses);
        $pendingStop = $state['pendingTimerStop'] ?? [];

        if (!is_array($pendingStop) || ($pendingStop['sessionKey'] ?? '') !== $sessionKey) {
            $pendingStop = [
                'sessionKey' => $sessionKey,
                'misses' => 0,
                'firstSeenAt' => date('Y-m-d H:i:s'),
            ];
        }

        $pendingStop['misses'] = (int)($pendingStop['misses'] ?? 0) + 1;
        $pendingStop['lastSeenAt'] = date('Y-m-d H:i:s');
        $state['pendingTimerStop'] = $pendingStop;

        return (int)$pendingStop['misses'] >= $requiredMisses;
    }

    /**
     * يحسب وقت السيشن المحسوب من آخر وقف بناءً على بيانات ERP.
     * يجيب صفوف اليوم من ERP، يحسب الملخص، ثم يرجع:
     *   - sessionRaw:     وقت السيشن الخام (lastActiveMin)
     *   - sessionCounted: المحسوب من هذه السيشن بعد الشروط
     *   - todaySummary:   ملخص اليوم كامل
     *   - weekSummary:    ملخص الأسبوع كامل
     */
    function buildStopDataFromErp($cookieFile, $stoppedTaskId, $lastActiveMin, $date, $weeklyTargetMin)
    {
        $todayRows   = fetchTimesheetsForRange($cookieFile, $date, $date);
        $todaySummary = calculateValidWeeklyMinutesFromTimesheets($todayRows);

        $weekRows    = fetchTimesheetsForWorkWeek($cookieFile, $date);
        $weekSummary = calculateValidWeeklyMinutesFromTimesheets($weekRows);

        // حاول تلاقي آخر صف للتاسك ده في ERP اليوم
        $sessionCounted = null;
        $sessionRaw     = (int)$lastActiveMin;

        $latestTaskRow = null;

        foreach (array_reverse($todaySummary['rows'] ?? []) as $row) {
            if ((string)$row['taskId'] === (string)$stoppedTaskId) {
                if ($latestTaskRow === null || (int)$row['rawMin'] > (int)$latestTaskRow['rawMin']) {
                    $latestTaskRow = $row;
                }

                // لو وقت الصف قريب من lastActiveMin (فارق ±5 دقايق) يبقى هو السيشن دي
                if (abs((int)$row['rawMin'] - $sessionRaw) <= 5) {
                    $sessionCounted = (int)$row['validMin'];
                    $sessionRaw     = (int)$row['rawMin'];
                    $latestTaskRow  = null;
                    break;
                }
            }
        }

        // لو آخر قراءة محفوظة قديمة لأن cron ماشتغلش فترة، اعتمد على صف ERP الحقيقي لنفس التاسك.
        if ($sessionCounted === null && $latestTaskRow !== null && (int)$latestTaskRow['rawMin'] > $sessionRaw) {
            $sessionRaw = (int)$latestTaskRow['rawMin'];
            $sessionCounted = (int)$latestTaskRow['validMin'];
        }

        // لو الصف مش موجود في ERP لسه (latency)، احسب تقريبي من task details
        if ($sessionCounted === null) {
            // استخدم taskSummary عشان تعرف إجمالي التاسك المحسوب النهارده
            $taskSummaryData = $todaySummary['taskSummary'][$stoppedTaskId] ?? null;

            if ($taskSummaryData) {
                // تقدير: المحسوب من السيشن = المحسوب الكلي للتاسك اليوم
                $sessionCounted = (int)$taskSummaryData['validMin'];
            } else {
                // مفيش بيانات: مفيش سقف يومي دلوقتي، فالمحسوب = الخام
                $sessionCounted = $sessionRaw;
            }
        }

        $effectiveWeeklyTarget = getWeeklyTargetMinutes($date, $weeklyTargetMin);

        return [
            'sessionRaw'       => $sessionRaw,
            'sessionCounted'   => $sessionCounted,
            'todaySummary'     => $todaySummary,
            'weekSummary'      => $weekSummary,
            'weekRemainingMin' => max(0, $effectiveWeeklyTarget - (int)$weekSummary['validMin']),
            'weeklyTargetMin'  => $effectiveWeeklyTarget,
        ];
    }

    function parseTimesheetRows($html)
    {
        $rows = [];
        $index = 0;

        if (!preg_match_all('/<tr id="timesheet_[^"]+">(.*?)<\/tr>/s', $html, $matches)) {
            return $rows;
        }

        foreach ($matches[1] as $rowHtml) {
            if (!preg_match('/data-url="https:\/\/erp\.designal\.cc\/tasks\/(\d+)\?ref=list"[^>]*>(.*?)<\/a>/s', $rowHtml, $taskMatch)) {
                continue;
            }

            preg_match('/<td class="timesheets_col_start_time">(.*?)<\/td>/s', $rowHtml, $dateMatch);
            preg_match('/<td class="timesheets_col_time">(.*?)<\/td>/s', $rowHtml, $timeMatch);
            preg_match('/<td class="timesheets_col_time timesheets_col_estimate">\s*(.*?)\s*<\/td>/s', $rowHtml, $estimateMatch);
            preg_match('/<td class="timesheets_col_time timesheets_col_total">\s*(.*?)\s*<\/td>/s', $rowHtml, $totalMatch);

            $dateValue = trim(strip_tags($dateMatch[1] ?? ''));
            $date = DateTime::createFromFormat('m-d-Y', $dateValue);

            if (!$date) {
                continue;
            }

            $rows[] = [
                'taskId' => $taskMatch[1],
                'title' => trim(strip_tags($taskMatch[2])),
                'date' => $date->format('Y-m-d'),
                'rawMin' => convertDurationToMinutes($timeMatch[1] ?? ''),
                'estimationMin' => convertDurationToMinutes($estimateMatch[1] ?? ''),
                'taskTotalMin' => convertDurationToMinutes($totalMatch[1] ?? ''),
                'index' => $index++,
            ];
        }

        usort($rows, function ($a, $b) {
            $dateCompare = strcmp($a['date'], $b['date']);

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ($a['index'] ?? 0) <=> ($b['index'] ?? 0);
        });

        return $rows;
    }

    function parseLoadMoreUrl($html)
    {
        $json = json_decode($html, true);

        foreach (($json['dom_attributes'] ?? []) as $item) {
            if (
                ($item['selector'] ?? '') === '#load-more-button' &&
                ($item['attr'] ?? '') === 'data-url' &&
                !empty($item['value'])
            ) {
                return html_entity_decode($item['value']);
            }
        }

        if (!preg_match('/id="load-more-button"[^>]*data-url="([^"]+)"/s', $html, $matches)) {
            return null;
        }

        return html_entity_decode($matches[1]);
    }

    function extractTimesheetsHtml($response)
    {
        $json = json_decode($response, true);
        $html = '';

        foreach (($json['dom_html'] ?? []) as $item) {
            $selector = $item['selector'] ?? '';

            if ($selector === '#timesheets-table-wrapper' || $selector === '#timesheets-td-container') {
                $html .= $item['value'] ?? '';
            }
        }

        return $html ?: $response;
    }

    function getWorkWeekRange($date)
    {
        $weekStart = weekKeyForDate($date);
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +4 days'));

        return [$weekStart, $weekEnd];
    }

    function fetchTimesheetsForRange($cookieFile, $startDate, $endDate)
    {
        $url = 'https://erp.designal.cc/timesheets/search?' . http_build_query([
            'ref' => 'list',
            'action' => 'search',
            'filter_timer_status' => 'stopped',
            'filter_date_created_start' => $startDate,
            'filter_date_created_end' => $endDate,
        ]);

        $url .= '&filter_timer_creatorid%5B%5D=29';

        $rows = [];
        $seenUrls = [];

        for ($page = 0; $page < 20 && $url; $page++) {
            if (!empty($seenUrls[$url])) {
                break;
            }

            $seenUrls[$url] = true;

            $res = curlRequest($url, null, $cookieFile);
            $html = extractTimesheetsHtml($res);
            $rows = array_merge($rows, parseTimesheetRows($html));
            $url = parseLoadMoreUrl($res);
        }

        usort($rows, function ($a, $b) {
            $dateCompare = strcmp($a['date'], $b['date']);

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ($a['index'] ?? 0) <=> ($b['index'] ?? 0);
        });

        return $rows;
    }

    function fetchTimesheetsForWorkWeek($cookieFile, $date)
    {
        [$weekStart, $weekEnd] = getWorkWeekRange($date);

        return fetchTimesheetsForRange($cookieFile, $weekStart, $weekEnd);
    }

    function calculateValidWeeklyMinutesFromTimesheets($rows)
    {
        $taskSpentSoFar = [];
        $taskCurrentWeekRaw = [];
        $taskTotal = [];
        $dayCounted = [];
        $dayRaw = [];
        $taskSummary = [];
        $rowResults = [];
        $rawTotal = 0;
        $validTotal = 0;

        foreach ($rows as $row) {
            $taskId = $row['taskId'];
            $taskCurrentWeekRaw[$taskId] = (int)($taskCurrentWeekRaw[$taskId] ?? 0) + (int)$row['rawMin'];
            $taskTotal[$taskId] = max((int)($taskTotal[$taskId] ?? 0), (int)($row['taskTotalMin'] ?? 0));
        }

        foreach ($taskCurrentWeekRaw as $taskId => $currentWeekRaw) {
            $taskSpentSoFar[$taskId] = max(0, (int)($taskTotal[$taskId] ?? 0) - (int)$currentWeekRaw);
        }

        foreach ($rows as $row) {
            $rawMin = (int)$row['rawMin'];
            $estMin = (int)$row['estimationMin'];
            $taskId = $row['taskId'];
            $date = $row['date'];

            $rawTotal += $rawMin;
            $dayRaw[$date] = (int)($dayRaw[$date] ?? 0) + $rawMin;

            $taskRemaining = $estMin > 0
                ? max(0, $estMin - (int)($taskSpentSoFar[$taskId] ?? 0))
                : $rawMin;

            $counted = min($rawMin, $taskRemaining);

            $taskSpentSoFar[$taskId] = (int)($taskSpentSoFar[$taskId] ?? 0) + $rawMin;
            $dayCounted[$date] = (int)($dayCounted[$date] ?? 0) + $counted;
            $validTotal += $counted;

            if (!isset($taskSummary[$taskId])) {
                $taskSummary[$taskId] = [
                    'title' => $row['title'],
                    'rawMin' => 0,
                    'validMin' => 0,
                    'estimationMin' => $estMin,
                    'taskTotalMin' => (int)($row['taskTotalMin'] ?? 0),
                ];
            }

            $taskSummary[$taskId]['rawMin'] += $rawMin;
            $taskSummary[$taskId]['validMin'] += $counted;
            $taskSummary[$taskId]['taskTotalMin'] = max($taskSummary[$taskId]['taskTotalMin'], (int)($row['taskTotalMin'] ?? 0));

            $rowResults[] = array_merge($row, [
                'validMin' => $counted,
                'ignoredMin' => max(0, $rawMin - $counted),
            ]);
        }

        return [
            'rawMin' => $rawTotal,
            'validMin' => $validTotal,
            'ignoredMin' => max(0, $rawTotal - $validTotal),
            'dayRaw' => $dayRaw,
            'dayCounted' => $dayCounted,
            'taskSpentSoFar' => $taskSpentSoFar,
            'taskSummary' => $taskSummary,
            'rows' => $rowResults,
        ];
    }

    function loadState($file)
    {
        if (!file_exists($file)) {
            return [];
        }

        return json_decode(file_get_contents($file), true) ?: [];
    }

    function saveState($file, $state)
    {
        file_put_contents(
            $file,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    function loadJsonFile($file)
    {
        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);

        if ($content === false || trim($content) === '') {
            return null;
        }

        $json = json_decode($content, true);

        return is_array($json) ? $json : null;
    }

    function saveJsonFile($file, $data)
    {
        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    function curlRequest(
        $url,
        $postData = null,
        $cookieFile = null,
        $extraHeaders = [],
        $asMultipart = false,
        &$curlInfo = null,
        &$curlError = null
    ) {
        $ch = curl_init($url);

        $defaultHeaders = [
            'User-Agent: Mozilla/5.0'
        ];
        if (!$asMultipart) {
            $defaultHeaders[] = 'X-Requested-With: XMLHttpRequest';
            $defaultHeaders[] = 'Accept: application/json, text/javascript, */*; q=0.01';
        } else {
            $defaultHeaders[] = 'Accept: application/json';
        }

        if ($cookieFile && file_exists($cookieFile)) {
            $cookieContent = file_get_contents($cookieFile);
            if (preg_match('/XSRF-TOKEN\s+(.+)$/m', $cookieContent, $m)) {
                $defaultHeaders[] = 'X-XSRF-TOKEN: ' . urldecode(trim($m[1]));
            }
        }

        $headers = array_merge($defaultHeaders, $extraHeaders);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($cookieFile) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        }

        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if (is_array($postData) && !$asMultipart) {
                $postData = http_build_query($postData);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        $maxRetries = 3;
        $retryCount = 0;
        $res = false;

        while ($retryCount < $maxRetries) {
            $res = curl_exec($ch);
            $curlInfo = curl_getinfo($ch);
            $curlError = curl_error($ch);
            $httpCode = (int)($curlInfo['http_code'] ?? 0);

            // الخروج إذا نجح الطلب
            if ($res !== false && $httpCode >= 200 && $httpCode < 500 && $httpCode !== 429) {
                break;
            }

            $retryCount++;
            if ($retryCount < $maxRetries) {
                sleep(1);
            }
        }

        curl_close($ch);

        return $res;
    }

    function login($config, $cookieFile)
    {
        debugLog("بحاول أعمل لوجين");

        $info  = null;
        $error = '';
        $res = curlRequest(
            'https://erp.designal.cc/login?action=initial',
            [
                'email'       => $config['EMAIL'],
                'password'    => $config['PASSWORD'],
                'remember_me' => 'on'
            ],
            $cookieFile,
            [],
            false,
            $info,
            $error
        );

        $httpCode = (int)($info['http_code'] ?? 0);

        // لو الرد JSON فيه redirect لصفحة اللوجين → اللوجين فشل
        $json = json_decode((string)$res, true);
        if (is_array($json)) {
            $redirectUrl = $json['redirect'] ?? $json['location'] ?? '';
            if ($redirectUrl && stripos($redirectUrl, 'login') !== false) {
                debugLog("لوجين فشل: الـ server بعت redirect لصفحة اللوجين");
                return false;
            }
            // لو فيه dom_html أو status success → نجح
            if (isset($json['dom_html']) || ($json['status'] ?? '') === 'success') {
                debugLog("لوجين نجح (JSON response)");
                return true;
            }
        }

        // لو HTTP 200 ومفيش أخطاء واضحة → نجح
        if ($httpCode >= 200 && $httpCode < 400 && $error === '') {
            debugLog("لوجين نجح (HTTP {$httpCode})");
            return true;
        }

        debugLog("لوجين فشل: HTTP {$httpCode}, خطأ: {$error}");
        return false;
    }





    function getActiveTaskData($cookieFile)
    {
        $info  = null;
        $error = '';
        $res = curlRequest(
            'https://erp.designal.cc/polling/timer',
            null,
            $cookieFile,
            [],
            false,
            $info,
            $error
        );

        $json = json_decode($res, true);

        $data = [
            'id'             => null,
            'minutes'        => 0,
            'title'          => '',
            'session_expired' => false,
            'request_failed' => false,
        ];

        // كشف انتهاء الجلسة أو فشل الطلب
        if (!is_array($json)) {
            $redirectUrl = '';
            if (is_string($res) && stripos($res, 'login') !== false && stripos($res, '<form') !== false) {
                $redirectUrl = 'login';
            }

            if ($redirectUrl) {
                debugLog("الجلسة انقطعت: الرد HTML صفحة لوجين");
                $data['session_expired'] = true;
            } else {
                debugLog("فشل جلب بيانات التايمر (لا يوجد رد JSON صحيح).");
                $data['request_failed'] = true;
            }
            return $data;
        }

        if (isset($json['redirect']) && stripos($json['redirect'], 'login') !== false) {
            debugLog("الجلسة انقطعت: الـ ERP بعت redirect لصفحة اللوجين");
            $data['session_expired'] = true;
            return $data;
        }

        if (isset($json['dom_html'])) {

            foreach ($json['dom_html'] as $item) {

                if (($item['selector'] ?? '') === '#my-timer-time-topnav') {

                    $cleanTime = strip_tags($item['value']);

                    $data['minutes'] = convertToMinutes(trim($cleanTime));
                }

                if (($item['selector'] ?? '') === '#active-timer-topnav-container') {

                    if (preg_match('/tasks\/timer\/(\d+)\/stop/', $item['value'], $matches)) {
                        $data['id'] = $matches[1];
                    }

                    if (preg_match('/<a[^>]*>(.*?)<\/a>/s', $item['value'], $matches)) {
                        $data['title'] = trim(strip_tags($matches[1]));
                    }
                }
            }
        }

        return $data;
    }

    function getTaskDetails($cookieFile, $taskId)
    {
        if (!$taskId) {
            return ['estimation' => 0, 'spent' => 0];
        }

        $res = curlRequest(
            "https://erp.designal.cc/tasks/{$taskId}",
            null,
            $cookieFile
        );

        $json = json_decode($res, true);

        $estimation = 0;
        $spent      = 0;

        if (isset($json['dom_html'])) {

            foreach ($json['dom_html'] as $item) {

                $value = $item['value'] ?? '';
                $plain = plainText($value);

                // جيب وقت التقدير
                if (
                    $estimation === 0 &&
                    ($found = findLabeledMinutes($plain, ['Estimate Time'])) > 0
                ) {
                    debugLog("قيمة التقدير من تفاصيل التاسك: " . fmtLog($found));
                    $estimation = $found;
                }

                // جيب الوقت المنجز من تفاصيل التاسك. في الداش بورد الجديدة الاسم غالبا My Timer أو Total Time مش Spent Time.
                if (
                    $spent === 0 &&
                    ($found = findLabeledMinutes($plain, ['My Timer', 'Spent Time', 'Total Time'])) > 0
                ) {
                    debugLog("قيمة الوقت المصروف من تفاصيل التاسك: " . fmtLog($found));
                    $spent = $found;
                }
            }
        }

        return [
            'estimation' => $estimation,
            'spent'      => $spent,
        ];
    }

    function getTelegramReplyKeyboard()
    {
        return [
            'keyboard' => [
                [
                    ['text' => '▶️ تشغيل التايمر'],
                    ['text' => '⏹️ إيقاف التايمر']
                ],
                [
                    ['text' => '📊 ملخص اليوم'],
                    ['text' => '📈 تقرير الأسبوع']
                ]
            ],
            'resize_keyboard' => true,
            'is_persistent'   => true,
        ];
    }

    function sendTelegram($msg, $config, $force = false)
    {
        if (!$force && isDayOff()) {
            debugLog("إرسال تليجرام متبعتش: اليوم عطلة أو إجازة رسمية");
            return false;
        }

        $token = $config['TG_BOT_TOKEN'] ?? '';
        $chatId = $config['TG_CHAT_ID'] ?? '';

        if ($token === '' || $chatId === '') {
            debugLog("إرسال تليجرام متبعتش: TG_BOT_TOKEN أو TG_CHAT_ID مش متظبطين");
            return false;
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $postData = [
            'chat_id'      => $chatId,
            'text'         => $msg,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(getTelegramReplyKeyboard()),
        ];

        $info = [];
        $error = '';
        $res = curlRequest($url, $postData, null, [], false, $info, $error);

        $httpCode = (int)($info['http_code'] ?? 0);

        $json = json_decode((string)$res, true);

        $success = is_array($json) && !empty($json['ok']);

        debugLog("إرسال تليجرام: " . ($success ? 'نجح' : 'فشل'));

        if (!$success) {
            debugLog("HTTP Code تليجرام: " . $httpCode);
            if ($error !== '') {
                debugLog("خطأ cURL تليجرام: " . $error);
            }
            debugLog("رد تليجرام: " . substr((string)$res, 0, 500));

            // لو فيه مشكلة في الـ Markdown parsing (مثلاً حروف خاصة)، حاول تبعت من غير parse_mode
            if (is_array($json) && stripos((string)($json['description'] ?? ''), 'parse') !== false) {
                $postData['parse_mode'] = '';
                $res = curlRequest($url, $postData, null, [], false, $info, $error);
                $json = json_decode((string)$res, true);
                $success = is_array($json) && !empty($json['ok']);
                debugLog("محاولة تانية بدون parse_mode: " . ($success ? 'نجح' : 'فشل'));
            }
        }

        return $success;
    }

    function sendTelegramWithButtons($msg, array $inlineKeyboard, $config)
    {
        if (isDayOff()) {
            debugLog("إرسال تليجرام متبعتش: اليوم عطلة أو إجازة رسمية");
            return null;
        }

        $token = $config['TG_BOT_TOKEN'] ?? '';
        $chatId = $config['TG_CHAT_ID'] ?? '';

        if ($token === '' || $chatId === '') {
            debugLog("إرسال تليجرام متبعتش: TG_BOT_TOKEN أو TG_CHAT_ID مش متظبطين");
            return null;
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $postData = [
            'chat_id'      => $chatId,
            'text'         => $msg,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ];

        $info = [];
        $error = '';
        $res = curlRequest($url, $postData, null, [], false, $info, $error);
        $json = json_decode((string)$res, true);

        if (is_array($json) && !empty($json['ok'])) {
            debugLog("إرسال تليجرام مع أزرار: نجح (message_id: " . ($json['result']['message_id'] ?? '') . ")");
            return $json['result'];
        }

        debugLog("فشل إرسال تليجرام مع أزرار: " . substr((string)$res, 0, 300));
        return null;
    }

    function answerTelegramCallback($callbackId, $text, $config)
    {
        $token = $config['TG_BOT_TOKEN'] ?? '';
        if ($token === '' || $callbackId === '') return false;

        $url = "https://api.telegram.org/bot{$token}/answerCallbackQuery";
        $postData = [
            'callback_query_id' => $callbackId,
            'text'              => $text,
        ];

        $info = [];
        $error = '';
        curlRequest($url, $postData, null, [], false, $info, $error);
        return true;
    }

    function editTelegramMessage($chatId, $messageId, $text, $config)
    {
        $token = $config['TG_BOT_TOKEN'] ?? '';
        if ($token === '' || !$chatId || !$messageId) return false;

        $url = "https://api.telegram.org/bot{$token}/editMessageText";
        $postData = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];

        $info = [];
        $error = '';
        $res = curlRequest($url, $postData, null, [], false, $info, $error);
        $json = json_decode((string)$res, true);

        return is_array($json) && !empty($json['ok']);
    }

    function processTelegramUpdates(&$state, $cookieFile, $config, $today)
    {
        $token = $config['TG_BOT_TOKEN'] ?? '';
        if ($token === '') return;

        $lastUpdateId = (int)($state['lastTelegramUpdateId'] ?? 0);
        $offset = $lastUpdateId > 0 ? $lastUpdateId + 1 : 0;

        $url = "https://api.telegram.org/bot{$token}/getUpdates?offset={$offset}&timeout=0";
        $info = [];
        $error = '';
        $res = curlRequest($url, null, null, [], false, $info, $error);
        $json = json_decode((string)$res, true);

        if (!is_array($json) || empty($json['ok']) || empty($json['result'])) {
            return;
        }

        foreach ($json['result'] as $update) {
            $updateId = (int)($update['update_id'] ?? 0);
            if ($updateId > $lastUpdateId) {
                $lastUpdateId = $updateId;
            }

            if (isset($update['callback_query'])) {
                $cq = $update['callback_query'];
                $cqId = $cq['id'] ?? '';
                $data = $cq['data'] ?? '';
                $msg = $cq['message'] ?? [];
                $chatId = $msg['chat']['id'] ?? ($config['TG_CHAT_ID'] ?? '');
                $messageId = $msg['message_id'] ?? null;

                debugLog("استقبال تفاعل تليجرام (callback_data: {$data})");

                if (strpos($data, 'start_timer:') === 0) {
                    $targetTaskId = trim(substr($data, 12));
                    $targetTitle = $state['promptTaskTitle'] ?? "تاسك #{$targetTaskId}";

                    // تشغيل التايمر
                    $started = startTimer($cookieFile, $targetTaskId);

                    if ($started) {
                        answerTelegramCallback($cqId, "✅ تم تشغيل التايمر!", $config);
                        editTelegramMessage(
                            $chatId,
                            $messageId,
                            "✅ *تم بدء تشغيل التايمر بنجاح!*\n\n📌 *التاسك:* {$targetTitle}\n⏱️ *وقت البدء:* " . nowTime(),
                            $config
                        );

                        desktopNotify("Timer Started", "تم بدء التايمر: {$targetTitle}");
                        summaryLog("▶️ تم بدء التايمر من تليجرام للتاسك {$targetTaskId}");

                        // تحديث الحالة فوراً
                        $state['lastActiveTaskId'] = $targetTaskId;
                        $state['lastActiveMin'] = 0;
                        $state['lastActiveDate'] = $today;
                        $state['lastActiveStartedAt'] = date('Y-m-d H:i:s');
                        $state['tasks'][$targetTaskId]['title'] = $targetTitle;
                        $sessionKey = buildSessionKey($targetTaskId, $today, $state['lastActiveStartedAt']);
                        $state['timerStartedSentSessions'][$sessionKey] = true;
                    } else {
                        answerTelegramCallback($cqId, "❌ فشل تشغيل التايمر على ERP", $config);
                        editTelegramMessage(
                            $chatId,
                            $messageId,
                            "❌ *فشل تشغيل التايمر على ERP!*\nيرجى المحاولة يدوياً على المنظومة.",
                            $config
                        );
                    }
                } elseif ($data === 'dismiss_timer') {
                    answerTelegramCallback($cqId, "تم الإلغاء", $config);
                    editTelegramMessage(
                        $chatId,
                        $messageId,
                        "❌ *تم الإلغاء*، لن يتم تشغيل التايمر الآن.",
                        $config
                    );
                    debugLog("تم إلغاء تشغيل التايمر من تليجرام بواسطة المستخدم");
                }
            }

            // استقبال ضغطات القائمة السفلية (Reply Keyboard)
            if (isset($update['message'])) {
                $msgObj = $update['message'];
                $text = trim($msgObj['text'] ?? '');
                $chatId = $msgObj['chat']['id'] ?? '';

                if ((string)$chatId !== (string)($config['TG_CHAT_ID'] ?? '')) {
                    continue;
                }

                debugLog("استقبال أمر من القائمة السفلية بتليجرام: {$text}");

                if (strpos($text, 'تشغيل التايمر') !== false) {
                    $activeCheck = getActiveTaskData($cookieFile);
                    if (!empty($activeCheck['task_id'])) {
                        $currentTitle = $activeCheck['title'] ?? "تاسك #{$activeCheck['task_id']}";
                        sendTelegram("⚠️ *التايمر شغال بالفعل حالياً!*\n\n📌 *التاسك الحالي:* {$currentTitle}", $config, true);
                    } else {
                        $candidate = findNextAvailableTask($cookieFile, null, $today);
                        if ($candidate) {
                            $started = startTimer($cookieFile, $candidate['taskId']);
                            if ($started) {
                                $msg  = "✅ *تم بدء تشغيل التايمر بنجاح!*\n\n";
                                $msg .= "📌 *التاسك:* {$candidate['title']}\n";
                                $msg .= "🎯 *التقدير:* " . formatMinutes($candidate['estimation']) . "\n";
                                $msg .= "⏳ *المستهلك:* " . formatMinutes($candidate['spent']) . "\n";
                                $msg .= "📉 *المتبقي:* " . formatMinutes($candidate['remaining']) . "\n";
                                $msg .= "⏱️ *وقت البدء:* " . nowTime();
                                sendTelegram($msg, $config, true);

                                desktopNotify("Timer Started", "تم بدء التايمر: {$candidate['title']}");
                                summaryLog("▶️ تم بدء التايمر من القائمة السفلية للتاسك {$candidate['taskId']}");

                                $state['lastActiveTaskId'] = $candidate['taskId'];
                                $state['lastActiveMin'] = 0;
                                $state['lastActiveDate'] = $today;
                                $state['lastActiveStartedAt'] = date('Y-m-d H:i:s');
                                $state['tasks'][$candidate['taskId']]['title'] = $candidate['title'];
                                $sessionKey = buildSessionKey($candidate['taskId'], $today, $state['lastActiveStartedAt']);
                                $state['timerStartedSentSessions'][$sessionKey] = true;
                            } else {
                                sendTelegram("❌ *فشل تشغيل التايمر على ERP!*\nيرجى التحقق من الجلسة أو المحاولة يدوياً على المنظومة.", $config, true);
                            }
                        } else {
                            sendTelegram("❌ *فشل بدء التايمر: لم يتم العثور على أي تاسك متاح!*\nمفيش تاسك اتكريت في الشهر المالي الحالي وعنده وقت متبقي.", $config, true);
                        }
                    }
                } elseif (strpos($text, 'إيقاف التايمر') !== false || strpos($text, 'ايقاف التايمر') !== false) {
                    $activeCheck = getActiveTaskData($cookieFile);
                    $runningTaskId = $activeCheck['task_id'] ?? $state['lastActiveTaskId'] ?? null;

                    if ($runningTaskId) {
                        $taskTitle = $activeCheck['title'] ?? ($state['tasks'][$runningTaskId]['title'] ?? "تاسك #{$runningTaskId}");
                        $stopped = stopTimer($cookieFile, $runningTaskId);
                        if ($stopped) {
                            sendTelegram("✅ *تم إيقاف التايمر بنجاح!*\n\n📌 *التاسك:* {$taskTitle}\n⏱️ *وقت الإيقاف:* " . nowTime(), $config, true);
                            desktopNotify("Timer Stopped", "تم إيقاف التايمر: {$taskTitle}");
                            summaryLog("⏹️ تم إيقاف التايمر من القائمة السفلية للتاسك {$runningTaskId}");

                            $oldSessionKey = buildSessionKey($runningTaskId, $today, $state['lastActiveStartedAt'] ?? '');
                            $state['countedSessions'][$oldSessionKey] = true;
                            $state['lastActiveTaskId'] = null;
                            $state['lastActiveMin'] = 0;
                            $state['lastActiveDate'] = null;
                            $state['lastActiveStartedAt'] = null;
                            unset($state['pendingTimerStop']);
                        } else {
                            sendTelegram("❌ *فشل إيقاف التايمر على ERP!*\nيرجى المحاولة يدوياً على المنظومة.", $config, true);
                        }
                    } else {
                        sendTelegram("ℹ️ *لا يوجد تايمر قيد التشغيل حالياً لإيقافه.*", $config, true);
                    }
                } elseif (strpos($text, 'ملخص اليوم') !== false) {
                    $todayRows = fetchTimesheetsForRange($cookieFile, $today, $today);
                    $todaySummary = calculateValidWeeklyMinutesFromTimesheets($todayRows);
                    $msg  = "📊 *ملخص النهارده* ({$today})\n\n";
                    $msg .= "⏱️ *إجمالي الشغل:* " . formatMinutes($todaySummary['rawMin']) . "\n";
                    $msg .= "✅ *مطابق للشروط:* " . formatMinutes($todaySummary['validMin']);
                    sendTelegram($msg, $config, true);
                } elseif (strpos($text, 'تقرير الأسبوع') !== false) {
                    [$weekStart, $weekEnd] = getWorkWeekRange($today);
                    $effectiveTarget = getWeeklyTargetMinutes($today, $config['WEEKLY_TARGET_MIN']);
                    $weekSummary = calculateValidWeeklyMinutesFromTimesheets(fetchTimesheetsForWorkWeek($cookieFile, $today));
                    $msg = formatPeriodReport("الأسبوع", $weekStart, $weekEnd, $weekSummary, $effectiveTarget);
                    sendTelegram($msg, $config, true);
                }
            }
        }

        $state['lastTelegramUpdateId'] = $lastUpdateId;
    }

    function isWeekend($date = null)
    {
        $timestamp = $date ? strtotime($date) : time();
        $day = (int)date('N', $timestamp);

        return $day === 5 || $day === 6;
    }

    function observedEgyptianHolidayDate($holiday)
    {
        $date = $holiday['date'] ?? null;
        if (!is_string($date)) return null;

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) return null;

        // نرحّل الإجازات الوطنية المعروفة فقط؛ الأعياد الدينية وشم النسيم في موعدها.
        $name = ($holiday['name'] ?? '') . ' ' . ($holiday['localName'] ?? '');
        $isNationalHoliday = preg_match(
            '/revolution|police|sinai|labou?r|armed forces|الثورة|ثورة|الشرطة|سيناء|العمال|القوات المسلحة|أكتوبر|اكتوبر/iu',
            $name
        ) === 1;

        // الأسبوع المصري بيبدأ الأحد. الخميس لا يترحّل مرة تانية حتى مع الكاش القديم.
        $day = (int)$parsed->format('w');
        if ($isNationalHoliday && $day <= 3) {
            return $parsed->modify('+' . (4 - $day) . ' days')->format('Y-m-d');
        }

        return $date;
    }

    function isPublicHoliday($date = null)
    {
        $timestamp = $date ? strtotime($date) : time();
        $year = date('Y', $timestamp);
        $checkDate = date('Y-m-d', $timestamp);

        $cacheFile = __DIR__ . "/holidays_{$year}.json";

        if (file_exists($cacheFile)) {
            $json = file_get_contents($cacheFile);
            $holidays = json_decode($json, true);
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://date.nager.at/api/v3/PublicHolidays/{$year}/EG");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response && $httpCode === 200) {
                $holidays = json_decode($response, true);
                if (is_array($holidays) && !empty($holidays) && isset($holidays[0]['date'])) {
                    file_put_contents($cacheFile, json_encode($holidays));
                } else {
                    $holidays = [];
                }
            } else {
                $holidays = [];
            }
        }

        if (is_array($holidays)) {
            foreach ($holidays as $holiday) {
                if (is_array($holiday)) {
                    $rawDate = $holiday['date'] ?? null;
                    $observedDate = observedEgyptianHolidayDate($holiday);

                    // فحص التاريخ الفعلي والتاريخ المرحّل
                    if ($rawDate === $checkDate || $observedDate === $checkDate) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    function isDayOff($date = null)
    {
        return isWeekend($date) || isPublicHoliday($date);
    }

    function calculateExpectedMinutes($startDate, $endDate)
    {
        $expected = 0;
        $current = strtotime($startDate);
        $end = strtotime($endDate);

        while ($current <= $end) {
            $currentDate = date('Y-m-d', $current);

            // لا يحسب عطلات نهاية الأسبوع (الجمعة والسبت) ولا الإجازات الرسمية
            if (!isDayOff($currentDate)) {
                $expected += 480; // 8 hours * 60 mins
            }
            $current = strtotime('+1 day', $current);
        }

        return $expected;
    }

    function getWeeklyTargetMinutes($date, $defaultTarget = 2400)
    {
        [$weekStart, $weekEnd] = getWorkWeekRange($date);
        $expected = calculateExpectedMinutes($weekStart, $weekEnd);

        return $expected > 0 ? $expected : (int)$defaultTarget;
    }

    function stopTimer($cookieFile, $taskId)
    {
        if (!$taskId) return false;

        $info = null;
        $error = '';
        $res = curlRequest(
            "https://erp.designal.cc/tasks/timer/{$taskId}/stop?source=topnav",
            [],
            $cookieFile,
            [],
            false,
            $info,
            $error
        );

        $httpCode = (int)($info['http_code'] ?? 0);

        debugLog("إيقاف التايمر للتاسك {$taskId}: HTTP {$httpCode}");
        debugLog("رد الإيقاف: " . substr((string)$res, 0, 300));

        return $httpCode >= 200 && $httpCode < 400;
    }

    function startTimer($cookieFile, $taskId)
    {
        if (!$taskId) return false;

        $info = null;
        $error = '';
        $res = curlRequest(
            "https://erp.designal.cc/tasks/timer/{$taskId}/start",
            [],
            $cookieFile,
            [],
            false,
            $info,
            $error
        );

        $httpCode = (int)($info['http_code'] ?? 0);

        debugLog("تشغيل التايمر للتاسك {$taskId}: HTTP {$httpCode}");
        debugLog("رد التشغيل: " . substr((string)$res, 0, 300));

        return $httpCode >= 200 && $httpCode < 400;
    }

    /**
     * يدور على أول تاسك متاح اتكريت في الشهر المالي الحالي وعنده وقت متبقي في التقدير.
     * 1. بيجيب التاسكات من kanban boards في ERP عبر /tasks/search
     * 2. بيفلتر بالتاسكات اللي اتكريتت في الشهر المالي الحالي
     * 3. بيتأكد من التقدير والوقت المستهلك
     * 4. لو ملقاش، بيعمل fallback على timesheets الشهر
     */
    function findNextAvailableTask($cookieFile, $excludeTaskId, $date)
    {
        [$monthStart, $monthEnd] = getMonthCycleRange($date);
        debugLog("البحث عن تاسك بديل في الشهر المالي الحالي: من {$monthStart} إلى {$monthEnd}");

        $candidates = [];

        // 1. جلب كل التاسكات المعينة للمستخدم من Kanban Board
        $info = null;
        $err = '';
        $res = curlRequest(
            'https://erp.designal.cc/tasks/search?ref=list&action=search',
            null,
            $cookieFile,
            [],
            false,
            $info,
            $err
        );

        $json = json_decode($res, true);
        $html = '';
        if (isset($json['dom_html'])) {
            foreach ($json['dom_html'] as $item) {
                if (($item['selector'] ?? '') === '#tasks-view-wrapper') {
                    $html = $item['value'];
                    break;
                }
            }
        }

        if (!empty($html)) {
            // استخراج boards
            $boards = explode('<div class="board">', $html);
            array_shift($boards);

            foreach ($boards as $bHtml) {
                $boardTitle = '';
                if (preg_match('/<div class="board-heading clearfix">.*?<div class="pull-left">(.*?)<\/div>/s', $bHtml, $bm)) {
                    $boardTitle = trim(strip_tags($bm[1]));
                }

                // استبعاد البوردات المنتهية أو المرفوضة أو المعلقة
                $lowerBoard = strtolower($boardTitle);
                if (in_array($lowerBoard, ['completed', 'reject', 'rejected', 'hold', 'on hold', 'archived'])) {
                    continue;
                }

                // استخراج الكروت
                preg_match_all('/<div class="kanban-card[^\"]*\"[^>]*data-task-id="(\d+)"/s', $bHtml, $cards, PREG_OFFSET_CAPTURE);

                foreach ($cards[1] as $idx => $c) {
                    $tid = $c[0];
                    if ((string)$tid === (string)$excludeTaskId) continue;

                    $pos = $c[1];
                    $nextPos = isset($cards[1][$idx + 1]) ? $cards[1][$idx + 1][1] : strlen($bHtml);
                    $cardHtml = substr($bHtml, $pos, $nextPos - $pos);

                    $title = '';
                    if (preg_match('/id="kanban_task_title_' . $tid . '">(.*?)(?:<span|<\/div)/s', $cardHtml, $tm)) {
                        $title = trim(strip_tags($tm[1]));
                    }

                    $created = '';
                    if (preg_match('/<strong>Created:<\/strong>\s*([0-9-]+)/', $cardHtml, $cm)) {
                        $created = trim($cm[1]);
                    }

                    $createdYmd = null;
                    if ($created) {
                        $dt = DateTime::createFromFormat('m-d-Y', $created);
                        if (!$dt) {
                            $dt = DateTime::createFromFormat('d-m-Y', $created);
                        }
                        if ($dt) {
                            $createdYmd = $dt->format('Y-m-d');
                        }
                    }

                    // شرط: يكون اتكريت في نفس الشهر المالي
                    if ($createdYmd && $createdYmd >= $monthStart && $createdYmd <= $monthEnd) {
                        $candidates[$tid] = [
                            'taskId' => $tid,
                            'title' => $title,
                            'board' => $boardTitle,
                            'created' => $createdYmd,
                        ];
                    }
                }
            }
        }

        debugLog("عدد التاسكات المرشحة التي تم إنشاؤها في الشهر المالي الحالي: " . count($candidates));

        // فحص تفاصيل كل تاسك مرشح للتأكد من وجود وقت متبقي
        foreach ($candidates as $candidate) {
            $details = getTaskDetails($cookieFile, $candidate['taskId']);
            $est = (int)($details['estimation'] ?? 0);
            $spent = (int)($details['spent'] ?? 0);

            debugLog("فحص تفاصيل المرشح #{$candidate['taskId']} {$candidate['title']} (بورد: {$candidate['board']}, تاريخ: {$candidate['created']}): تقدير=" . fmtLog($est) . "، مصروف=" . fmtLog($spent));

            if ($est > 0 && $spent < $est) {
                return [
                    'taskId' => $candidate['taskId'],
                    'title' => $candidate['title'],
                    'estimation' => $est,
                    'spent' => $spent,
                    'remaining' => $est - $spent,
                ];
            }
        }

        // 2. Fallback: فحص timesheets الشهر المالي لو ملقيناش في البورد
        debugLog("جاري البحث كـ fallback من timesheets الشهر المالي...");
        $rows = fetchTimesheetsForRange($cookieFile, $monthStart, $date);
        $summary = calculateValidWeeklyMinutesFromTimesheets($rows);

        $fallbackCandidates = [];
        foreach ($summary['taskSummary'] as $tid => $task) {
            if ((string)$tid === (string)$excludeTaskId) continue;
            if (isset($candidates[$tid])) continue;

            $est = (int)($task['estimationMin'] ?? 0);
            $total = (int)($task['taskTotalMin'] ?? 0);

            if ($est > 0 && $total < $est) {
                $fallbackCandidates[] = [
                    'taskId' => $tid,
                    'title' => $task['title'],
                    'approxRemaining' => $est - $total,
                ];
            }
        }

        if (!empty($fallbackCandidates)) {
            usort($fallbackCandidates, function ($a, $b) {
                return $b['approxRemaining'] <=> $a['approxRemaining'];
            });

            foreach (array_slice($fallbackCandidates, 0, 3) as $fc) {
                $details = getTaskDetails($cookieFile, $fc['taskId']);
                $est = (int)($details['estimation'] ?? 0);
                $spent = (int)($details['spent'] ?? 0);

                if ($est > 0 && $spent < $est) {
                    return [
                        'taskId' => $fc['taskId'],
                        'title' => $fc['title'],
                        'estimation' => $est,
                        'spent' => $spent,
                        'remaining' => $est - $spent,
                    ];
                }
            }
        }

        debugLog("مفيش أي تاسك متاح مؤهل للتشغيل في الشهر المالي الحالي ({$monthStart} - {$monthEnd})");
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Start
    |--------------------------------------------------------------------------
    */

    try {

        // Lock file لمنع تشغيل أكتر من نسخة في نفس الوقت
        $lockFp = fopen($LOCK_FILE, 'w');

        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            // نسخة تانية شغالة، اخرج بهدوء
            if ($lockFp) {
                fclose($lockFp);
            }
            exit;
        }

        $cliArgs = $argv ?? [];

        if (in_array('--verbose', $cliArgs, true)) {
            $CONFIG['VERBOSE_LOG'] = true;
        }

        debugLog("=========== بداية تشغيل السكريبت ===========");

        $COOKIE_FILE = __DIR__ . '/cookie_yehia.txt';
        $STATE_FILE  = __DIR__ . '/state_yehia.json';

        do {

            $loginOk = login($CONFIG, $COOKIE_FILE);
            if (!$loginOk) {
                summaryLog("⚠️ فشل تسجيل الدخول أو انقطعت الجلسة على ERP");
                summaryLog("متأكد إن الباسورد والكوكيز سليمة؟");
                break;
            }

            if (in_array('--details', $cliArgs, true)) {
                printDetailsReport($COOKIE_FILE, todayDate(), $CONFIG['WEEKLY_TARGET_MIN']);
                break;
            }

            if (in_array('--report', $cliArgs, true)) {
                summaryLog(buildFullReportMessage($COOKIE_FILE, todayDate(), $CONFIG, "تقرير يدوي"));
                break;
            }

            $state = loadState($STATE_FILE);

            $today = todayDate();
            $currentHM = date('H:i');
            $previousStateDate = $state['date'] ?? $today;

            debugLog("تاريخ النهارده: {$today}");
            debugLog("تاريخ آخر حالة محفوظة: {$previousStateDate}");
            debugLog("المطلوب في الأسبوع: " . formatMinutes($CONFIG['WEEKLY_TARGET_MIN']));

            if (($state['date'] ?? '') !== $today) {

                debugLog("يوم جديد، بصفر تنبيهات اليوم");

                $state['date'] = $today;
                $state['targetReachedSent'] = false;
                $state['lastMorningAlertSent'] = '';
                $state['lastMorningPromptDate'] = '';
                $state['firstTimerStartedSent'] = false;
                $state['timerStartedSentSessions'] = [];
                $state['longRunningTimerSentSessions'] = [];
                unset($state['pendingTimerStartedSessionKey']);
                unset($state['pendingTimerStop']);
            }

            $state = ensureDailyStats($state, $today);

            if (!isset($state['timerStartedSentSessions']) || !is_array($state['timerStartedSentSessions'])) {
                $state['timerStartedSentSessions'] = [];
            }

            if (!isset($state['longRunningTimerSentSessions']) || !is_array($state['longRunningTimerSentSessions'])) {
                $state['longRunningTimerSentSessions'] = [];
            }

            // فحص تفاعلات أزرار تليجرام (تشغيل أو إلغاء)
            processTelegramUpdates($state, $COOKIE_FILE, $CONFIG, $today);

            $activeData = getActiveTaskData($COOKIE_FILE);

            // لو الجلسة انقطعت، حاول تعمل re-login مرة واحدة
            if (!empty($activeData['session_expired'])) {
                summaryLog("⚠️ الجلسة انقطعت، بحاول أعمل re-login...");
                $reLoginOk = login($CONFIG, $COOKIE_FILE);
                if (!$reLoginOk) {
                    summaryLog("❌ فشل الـ re-login، سكيبت التشغيل دا.");
                    saveState($STATE_FILE, $state);
                    break;
                }
                summaryLog("✅ re-login نجح, بعمل polling تاني.");
                $activeData = getActiveTaskData($COOKIE_FILE);
                if (!empty($activeData['session_expired'])) {
                    summaryLog("❌ الجلسة لسه منقطعة بعد re-login، سكيبت.");
                    saveState($STATE_FILE, $state);
                    break;
                }
            }

            if (!empty($activeData['request_failed'])) {
                summaryLog("⚠️ فشل جلب بيانات التايمر (خطأ في الشبكة أو الـ ERP)، سكيبت التشغيل دا عشان ميبعتش تنبيه وقف بالغلط.");
                saveState($STATE_FILE, $state);
                break;
            }

            $activeMin = $activeData['minutes'];
            $taskId    = $activeData['id'];
            $taskTitle = $activeData['title'];


            debugLog("التاسك الشغال دلوقتي: " . ($taskId ?: 'مفيش'));
            debugLog("اسم التاسك: " . ($taskTitle ?: 'فاضي'));
            debugLog("وقت التايمر الشغال: " . fmtLog($activeMin));


            /*
        |--------------------------------------------------------------------------
        | Timer Stopped Detection
        |--------------------------------------------------------------------------
        */

            $lastActiveTaskId = $state['lastActiveTaskId'] ?? null;
            $lastActiveMin    = $state['lastActiveMin']    ?? 0;
            $lastActiveDate   = $state['lastActiveDate']   ?? $previousStateDate;
            $lastActiveStartedAt = $state['lastActiveStartedAt'] ?? null;

            debugLog("آخر تاسك كان محفوظ: " . ($lastActiveTaskId ?: 'مفيش'));
            debugLog("وقت آخر تاسك محفوظ: " . fmtLog($lastActiveMin));
            debugLog("تاريخ آخر تاسك محفوظ: " . ($lastActiveDate ?: 'مفيش'));
            debugLog("آخر سيشن بدأت إمتى: " . ($lastActiveStartedAt ?: 'مفيش'));

            if (!$taskId && $lastActiveTaskId) {

                debugLog("شرط الوقف اتحقق: مفيش تاسك شغال دلوقتي، وكان فيه تاسك محفوظ قبل كده");
                debugLog("التايمر وقف. آخر تاسك كان: {$lastActiveTaskId}");

                $stoppedTitle = $state['tasks'][$lastActiveTaskId]['title'] ?? $lastActiveTaskId;
                $sessionDate  = $lastActiveDate ?: $today;
                $sessionKey   = buildSessionKey($lastActiveTaskId, $sessionDate, $lastActiveStartedAt);

                $stopConfirmed = confirmTimerStopMiss($state, $sessionKey, $CONFIG['STOP_CONFIRMATION_MISSES']);
                $missCount = (int)($state['pendingTimerStop']['misses'] ?? 0);

                if (!$stopConfirmed) {
                    debugLog("قراءة مفيش تاسك رقم {$missCount} للسيشن {$sessionKey}، مستني تأكيد قبل رسالة الوقف");
                } else {
                    $state = ensureDailyStats($state, $sessionDate);

                    debugLog("كود السيشن اللي وقفت: {$sessionKey}");

                    // state.json بيُستخدم هنا فقط لمنع تكرار الرسالة
                    if (!empty($state['countedSessions'][$sessionKey])) {
                        debugLog("الرسالة دي اترسلت قبل كده (countedSessions)، مش هعيد.");
                    } else {
                        // جيب البيانات من ERP
                        $stopData = buildStopDataFromErp(
                            $COOKIE_FILE,
                            $lastActiveTaskId,
                            $lastActiveMin,
                            $sessionDate,
                            $CONFIG['WEEKLY_TARGET_MIN']
                        );

                        $sessionRaw      = $stopData['sessionRaw'];
                        $sessionCounted  = $stopData['sessionCounted'];
                        $todaySummary    = $stopData['todaySummary'];
                        $weekSummary     = $stopData['weekSummary'];
                        $weekRemainingMin = $stopData['weekRemainingMin'];

                        $todayRawMin     = (int)($todaySummary['dayRaw'][$sessionDate] ?? 0);
                        $todayCountedMin = (int)($todaySummary['dayCounted'][$sessionDate] ?? 0);
                        $weekRawMin      = (int)$weekSummary['rawMin'];
                        $weekCountedMin  = (int)$weekSummary['validMin'];

                        // Estimate info من task summary في ERP
                        $taskErpData  = $todaySummary['taskSummary'][$lastActiveTaskId]
                            ?? $weekSummary['taskSummary'][$lastActiveTaskId]
                            ?? [];
                        $stoppedEst   = (int)($taskErpData['estimationMin'] ?? 0);
                        $taskTotalMin = (int)($taskErpData['taskTotalMin'] ?? 0);

                        debugLog("ERP - sessionRaw=" . fmtLog($sessionRaw) . ", sessionCounted=" . fmtLog($sessionCounted));
                        debugLog("ERP - todayRaw=" . fmtLog($todayRawMin) . ", todayCounted=" . fmtLog($todayCountedMin));
                        debugLog("ERP - weekRaw=" . fmtLog($weekRawMin) . ", weekCounted=" . fmtLog($weekCountedMin));

                        // بناء رسالة الوقف
                        $msg  = "⏹️ *تم إيقاف التايمر*\n";
                        $msg .= "📝 *التاسك:* {$stoppedTitle}\n\n";
                        $msg .= "📊 *جدول الأوقات (الكلي مقابل المحسوب):*\n\n";

                        $msg .= "⏱️ *السيشن:*\n";
                        $msg .= "▫️ الكلي (الفعلي): " . formatMinutes($sessionRaw) . "\n";
                        $msg .= "▫️ المحسوب: " . formatMinutes($sessionCounted);
                        if ($stoppedEst > 0) {
                            $msg .= " (من أصل " . formatMinutes($stoppedEst) . ")\n";
                        } else {
                            $msg .= "\n";
                        }

                        $msg .= "\n📅 *اليوم:*\n";
                        $msg .= "▫️ الكلي (الفعلي): " . formatMinutes($todayRawMin) . "\n";
                        $msg .= "▫️ المحسوب: " . formatMinutes($todayCountedMin) . "\n";

                        $weeklyTargetDisplay = $stopData['weeklyTargetMin'] ?? $CONFIG['WEEKLY_TARGET_MIN'];

                        $msg .= "\n📆 *الأسبوع:*\n";
                        $msg .= "▫️ الكلي (الفعلي): " . formatMinutes($weekRawMin) . "\n";
                        $msg .= "▫️ المحسوب: " . formatMinutes($weekCountedMin) . " (الهدف " . formatMinutes($weeklyTargetDisplay) . ")\n";

                        $msg .= "\n⏳ *باقي على هدف الأسبوع:* " . formatMinutes($weekRemainingMin);

                        // لو النهارده 25 في الشهر، ضيف ملخص الشهر للرسالة
                        if ((int)date('j', strtotime($sessionDate)) === 25) {
                            [$monthStart, $monthEnd] = getMonthCycleRange($sessionDate);
                            $monthRows = fetchTimesheetsForRange($COOKIE_FILE, $monthStart, $monthEnd);
                            $monthSummary = calculateValidWeeklyMinutesFromTimesheets($monthRows);

                            $msg .= "\n\n📈 *تقفيل الشهر* ({$monthStart} لحد {$monthEnd}):";
                            $msg .= "\nإجمالي: " . formatMinutes($monthSummary['rawMin']) . " | محسوب: " . formatMinutes($monthSummary['validMin']);

                            if ((int)$monthSummary['ignoredMin'] > 0) {
                                $msg .= "\n❌ مستبعد: " . formatMinutes($monthSummary['ignoredMin']);
                            }
                        }

                        // تنبيه لو وقت السيشن أكبر من المحسوب
                        if ($sessionCounted < $sessionRaw) {
                            $ignored = $sessionRaw - $sessionCounted;
                            $msg .= "\n\n⚠️ *تنبيه:* " . formatMinutes($ignored) . " مش محسوبة (تجاوز السقف).";
                        }

                        // تنبيه لو التاسك عدى الـ Estimate
                        if ($stoppedEst > 0 && $taskTotalMin > $stoppedEst) {
                            $over = $taskTotalMin - $stoppedEst;
                            $msg .= "\n🚨 *تجاوز الوقت!* تخطيت الـ Estimate بـ " . formatMinutes($over) . "، الزيادة مش هتتحسب.";
                        }

                        $stoppedSent = sendTelegram($msg, $CONFIG);
                        summaryLog("تنبيه إيقاف التايمر للتاسك {$lastActiveTaskId}: " . ($stoppedSent ? 'اترسل' : 'فشل'));

                        desktopNotify(
                            "Timer Stopped",
                            "اليوم: " . formatMinutes($todayCountedMin) . " - الأسبوع: " . formatMinutes($weekCountedMin)
                        );

                        if ($stoppedSent) {
                            // امسح بيانات التايمر من state وسجل السيشن كـ مرسلة
                            $state['lastActiveTaskId']    = null;
                            $state['lastActiveMin']       = 0;
                            $state['lastActiveDate']      = null;
                            $state['lastActiveStartedAt'] = null;
                            $state['countedSessions'][$sessionKey] = true;
                            unset($state['pendingTimerStop']);
                        } else {
                            debugLog("هسيب بيانات آخر تايمر محفوظة عشان أحاول أبعت تنبيه الوقف تاني");
                        }
                    }
                }
            } elseif ($taskId) {

                debugLog("شرط الوقف متحققش: لسه فيه تاسك شغال");

                if (!empty($state['pendingTimerStop'])) {
                    debugLog("لقيت تايمر شغال بعد pending stop، هلغي تأكيد الوقف المؤقت");
                    unset($state['pendingTimerStop']);
                }

                $isNewActiveSession = !$lastActiveTaskId ||
                    $lastActiveTaskId !== $taskId ||
                    $lastActiveDate !== $today ||
                    $activeMin < $lastActiveMin;

                if ($isNewActiveSession) {
                    debugLog("دي سيشن جديدة: task={$taskId}, today={$today}, lastTask=" . ($lastActiveTaskId ?: 'none') . ", lastDate=" . ($lastActiveDate ?: 'none') . ", activeMin={$activeMin}, lastActiveMin={$lastActiveMin}");
                }

                $currentStartedAt = $isNewActiveSession ? date('Y-m-d H:i:s') : $lastActiveStartedAt;
                $startedSessionKey = buildSessionKey($taskId, $today, $currentStartedAt);

                // احفظ التاسك الحالي والوقت عشان نستخدمهم لو التايمر وقف
                $state['lastActiveTaskId']        = $taskId;
                $state['lastActiveMin']           = $activeMin;
                $state['lastActiveDate']          = $isNewActiveSession ? $today : $lastActiveDate;
                $state['lastActiveStartedAt']     = $currentStartedAt;
                $state['tasks'][$taskId]['title'] = $taskTitle;

                $pendingStartedSessionKey = $state['pendingTimerStartedSessionKey'] ?? null;
                $startedSessionAlreadySent = !empty($state['timerStartedSentSessions'][$startedSessionKey]);

                if (
                    !$startedSessionAlreadySent &&
                    (
                        $isNewActiveSession ||
                        empty($state['firstTimerStartedSent']) ||
                        $pendingStartedSessionKey === $startedSessionKey
                    )
                ) {

                    if (empty($state['firstTimerStartedSent'])) {
                        $msg = "▶️ *تم تشغيل التايمر لأول مرة اليوم*\n";
                    } elseif ($lastActiveTaskId && $lastActiveTaskId !== $taskId) {
                        $msg = "▶️ *تم تشغيل تايمر تاسك جديد*\n";
                    } else {
                        $msg = "▶️ *تم تشغيل التايمر*\n";
                    }

                    $msg .= "📝 *التاسك:* {$taskTitle}\n";
                    $startedAtText = $currentStartedAt ? date('H:i', strtotime($currentStartedAt)) : date('H:i');
                    $msg .= "⏰ *البداية:* " . $startedAtText;

                    if ($lastActiveTaskId && $lastActiveTaskId !== $taskId) {
                        $previousTitle = $state['tasks'][$lastActiveTaskId]['title'] ?? $lastActiveTaskId;
                        $msg .= "\n⏮️ *التاسك السابق:* {$previousTitle}";
                    }

                    $startedTaskDetails = getTaskDetails($COOKIE_FILE, $taskId);
                    $startedEstMin = (int)($startedTaskDetails['estimation'] ?? 0);
                    $startedSpentMin = (int)($startedTaskDetails['spent'] ?? 0);
                    $startedProgressMin = max($startedSpentMin, (int)$activeMin);

                    if ($startedEstMin > 0) {
                        $msg .= "\n\n🎯 *التقدير:* " . formatMinutes($startedEstMin) . " | ⏳ *المستهلك:* " . formatMinutes($startedProgressMin);
                        $msg .= "\n📉 *المتبقي للتاسك:* " . formatMinutes(max(0, $startedEstMin - $startedProgressMin));
                    } elseif ($startedSpentMin > 0 || $activeMin > 0) {
                        $msg .= "\n\n⏳ *المستهلك:* " . formatMinutes($startedProgressMin);
                    }

                    $startedSent = sendTelegram($msg, $CONFIG);
                    summaryLog("تنبيه تشغيل التايمر للتاسك {$taskId}: " . ($startedSent ? 'اترسل' : 'فشل'));

                    desktopNotify(
                        "Timer Started",
                        "تم تشغيل التايمر على {$taskTitle}"
                    );

                    debugLog("تنبيه تشغيل التايمر: " . ($startedSent ? 'اترسل' : 'فشل'));

                    if ($startedSent) {
                        $state['timerStartedSentSessions'][$startedSessionKey] = true;
                        unset($state['pendingTimerStartedSessionKey']);

                        if (empty($state['firstTimerStartedSent'])) {
                            $state['firstTimerStartedSent'] = true;
                        }
                    } else {
                        $state['pendingTimerStartedSessionKey'] = $startedSessionKey;
                    }
                }

                // لو تاسك جديد اشتغل، ريست الـ timerStoppedSent عشان يبعت تاني
                if ($lastActiveTaskId && $lastActiveTaskId !== $taskId) {
                    debugLog("تاسك جديد اشتغل: {$taskId} بدل {$lastActiveTaskId}");
                    $state['tasks'][$taskId]['timerStoppedSent'] = false;
                }

                $longRunningAlertMin = (int)$CONFIG['LONG_RUNNING_TIMER_ALERT_MIN'];
                $longRunningSessionKey = $startedSessionKey . '|long-running|' . $longRunningAlertMin;

                $sessionDurationMin = 0;
                if ($currentStartedAt) {
                    $sessionDurationMin = (int)floor((time() - strtotime($currentStartedAt)) / 60);
                }

                if (
                    $longRunningAlertMin > 0 &&
                    $sessionDurationMin >= $longRunningAlertMin &&
                    empty($state['longRunningTimerSentSessions'][$longRunningSessionKey])
                ) {
                    $msg  = "⚠️ *تنبيه: التايمر شغال فترة طويلة!*\n";
                    $msg .= "في السيشن الحالية شغال بقاله: *" . formatMinutes($sessionDurationMin) . "*\n";
                    $msg .= "(إجمالي اليوم للتاسك: " . formatMinutes($activeMin) . ")\n";
                    $msg .= "📝 *التاسك:* {$taskTitle}\n";
                    $startedAtText = $currentStartedAt ? date('H:i', strtotime($currentStartedAt)) : 'غير معروف';
                    $msg .= "⏰ *البداية:* " . $startedAtText;

                    $longRunningSent = sendTelegram($msg, $CONFIG);
                    summaryLog("تنبيه التايمر الطويل للتاسك {$taskId}: " . ($longRunningSent ? 'اترسل' : 'فشل'));

                    desktopNotify(
                        "Timer Still Running",
                        "التايمر شغال من " . formatMinutes($sessionDurationMin)
                    );

                    if ($longRunningSent) {
                        $state['longRunningTimerSentSessions'][$longRunningSessionKey] = true;
                    }
                }
            } else {
                debugLog("شرط الوقف متحققش: مفيش تاسك شغال ومفيش تاسك محفوظ من قبل");
            }

            /*
        |--------------------------------------------------------------------------
        | Morning Reminder
        |--------------------------------------------------------------------------
        */

            /*
            |--------------------------------------------------------------------------
            | Morning 8-10 AM Interactive Prompt (سؤال تشغيل التايمر بالأزرار)
            |--------------------------------------------------------------------------
            */

            if (
                !isDayOff($today) &&
                $currentHM >= '08:00' &&
                $currentHM <= '10:00' &&
                empty($taskId) &&
                ($state['lastMorningPromptDate'] ?? '') !== $today
            ) {
                $candidate = findNextAvailableTask($COOKIE_FILE, null, $today);

                if ($candidate) {
                    $msg  = "⏰ *صباح الخير يا هندسة!*\n\n";
                    $msg .= "هل تريد بدء تشغيل التايمر الآن؟\n";
                    $msg .= "📌 *التاسك:* {$candidate['title']}\n";
                    $msg .= "🎯 *التقدير:* " . formatMinutes($candidate['estimation']) . "\n";
                    $msg .= "⏳ *المستهلك:* " . formatMinutes($candidate['spent']) . "\n";
                    $msg .= "📉 *المتبقي:* " . formatMinutes($candidate['remaining']);

                    $buttons = [
                        [
                            ['text' => '✅ نعم، ابدأ التايمر', 'callback_data' => "start_timer:{$candidate['taskId']}"],
                            ['text' => '❌ لا', 'callback_data' => 'dismiss_timer']
                        ]
                    ];

                    $sent = sendTelegramWithButtons($msg, $buttons, $CONFIG);

                    if ($sent) {
                        $state['lastMorningPromptDate'] = $today;
                        $state['promptTaskId'] = $candidate['taskId'];
                        $state['promptTaskTitle'] = $candidate['title'];
                        $state['promptMessageId'] = $sent['message_id'] ?? null;
                        debugLog("تم إرسال رسالة تشغيل التايمر التفاعلية بالتاسك #{$candidate['taskId']}");
                    }
                } else {
                    $msg = "🌅 *صباح الخير يا هندسة!*\nمفيش تاسك متاح في الشهر المالي حالياً، متنساش تراجع التاسكات على ERP.";
                    $sent = sendTelegram($msg, $CONFIG);
                    if ($sent) {
                        $state['lastMorningPromptDate'] = $today;
                    }
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Daily 10 PM Report
        |--------------------------------------------------------------------------
        */

            if (
                $currentHM >= '22:00' &&
                $currentHM <= '22:09' &&
                ($state['lastDaily10PMAlertSent'] ?? '') !== $today
            ) {
                [$monthStart, $monthEnd] = getMonthCycleRange($today);
                $expectedMin = calculateExpectedMinutes($monthStart, $today);

                $monthRows = fetchTimesheetsForRange($COOKIE_FILE, $monthStart, $today);
                $monthSummary = calculateValidWeeklyMinutesFromTimesheets($monthRows);
                $workedMin = (int)$monthSummary['validMin'];

                $diffMin = $workedMin - $expectedMin;
                $isPositive = $diffMin >= 0;
                $absDiff = abs($diffMin);

                $msg  = "📊 *تقرير الإنجاز الشهري* (حتى اليوم)\n";
                $msg .= "دورة الشهر: من {$monthStart} لحد {$today}\n\n";
                $msg .= "🎯 *المطلوب إنجازه حتى الآن:* " . formatMinutes($expectedMin) . "\n";
                $msg .= "✅ *المنجز والمحسوب فعلياً:* " . formatMinutes($workedMin) . "\n\n";

                if ($expectedMin > 0) {
                    if ($isPositive) {
                        $msg .= "📈 *حالة الإنجاز:* متقدم بـ " . formatMinutes($absDiff);
                    } else {
                        $msg .= "📉 *حالة الإنجاز:* متأخر بـ " . formatMinutes($absDiff);
                    }
                }

                $dailyReportSent = sendTelegram($msg, $CONFIG);

                debugLog("تقرير 10 بليل: " . ($dailyReportSent ? 'اترسل' : 'فشل'));

                if ($dailyReportSent) {
                    $state['lastDaily10PMAlertSent'] = $today;
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Estimation Alerts
        |--------------------------------------------------------------------------
        */

            debugLog("===== بداية فحص تقدير التاسك =====");

            // نجيب صفوف اليوم مرة واحدة ونستخدمها في فحص تقدير التاسك
            $todayRows = fetchTimesheetsForRange($COOKIE_FILE, $today, $today);

            if ($taskId) {

                $taskDetails = getTaskDetails($COOKIE_FILE, $taskId);

                $estMin   = $taskDetails['estimation'];
                $spentMin = $taskDetails['spent'];

                $stateTaskRawMin = getTaskRawMinutesFromState($state, $taskId);
                $todayTaskRawMin = getTaskRawMinutesFromRows($todayRows, $taskId);

                $elapsedMin = max(
                    (int)$activeMin,
                    (int)$spentMin,
                    (int)$stateTaskRawMin + (int)$activeMin,
                    (int)$todayTaskRawMin + (int)$activeMin
                );

                $state['tasks'][$taskId]['estimation'] = $estMin;

                debugLog("تقدير التاسك: " . fmtLog($estMin));
                debugLog("الوقت المصروف من تفاصيل التاسك: " . fmtLog($spentMin));
                debugLog("وقت التاسك المتوقف من state: " . fmtLog($stateTaskRawMin));
                debugLog("وقت التاسك المتوقف من timesheets النهارده: " . fmtLog($todayTaskRawMin));
                debugLog("الوقت المستخدم لحساب التنبيهات: " . fmtLog($elapsedMin));

                if ($estMin > 0) {

                    $remainingMin = $estMin - $elapsedMin;

                    debugLog("فاضل على تقدير التاسك: " . fmtLog($remainingMin));

                    $alerts = [15, 10, 5];

                    $matchedAlert = null;

                    foreach ($alerts as $alertMin) {

                        $sentKey = "estWarning{$alertMin}Sent";
                        $alreadySent = !empty($state['tasks'][$taskId][$sentKey]);

                        debugLog("براجع تنبيه {$alertMin} دقيقة");

                        if ($remainingMin > 0 && $remainingMin <= $alertMin && !$alreadySent) {
                            $matchedAlert = $alertMin;
                        }
                    }

                    if ($matchedAlert !== null) {

                        debugLog("تنبيه {$matchedAlert} دقيقة اتحقق");

                        $msg  = "⚠️ *تنبيه وقت!*\nباقي *{$matchedAlert} دقيقة* على التقدير الزمني للتاسك:\n";
                        $msg .= "📝 {$taskTitle}";

                        $sent = sendTelegram($msg, $CONFIG);
                        summaryLog("تنبيه {$matchedAlert} دقيقة للتاسك {$taskId}: " . ($sent ? 'اترسل' : 'فشل'));

                        desktopNotify(
                            "Task Reminder",
                            "باقي {$matchedAlert} دقيقة على {$taskTitle}"
                        );

                        if ($sent) {
                            foreach ($alerts as $alertMin) {
                                if ($alertMin >= $matchedAlert) {
                                    $state['tasks'][$taskId]["estWarning{$alertMin}Sent"] = true;
                                }
                            }
                        }
                    }

                    /*
                |--------------------------------------------------------------------------
                | Exceeded Alert
                |--------------------------------------------------------------------------
                */

                    if (
                        $remainingMin <= 0 &&
                        empty($state['tasks'][$taskId]['estExceededSent'])
                    ) {

                        debugLog("التاسك عدى وقت التقدير");

                        $msg  = "🚨 *تجاوز الوقت!*\nتخطيت الوقت المقدر للتاسك:\n";
                        $msg .= "📝 {$taskTitle}\n(أي وقت إضافي حالياً مش هيتحسب)";

                        $exceededSent = sendTelegram($msg, $CONFIG);
                        summaryLog("تنبيه تجاوز تقدير التاسك {$taskId}: " . ($exceededSent ? 'اترسل' : 'فشل'));

                        desktopNotify(
                            "Task Exceeded",
                            "تم تجاوز وقت التاسك"
                        );

                        if ($exceededSent) {
                            $state['tasks'][$taskId]['estExceededSent'] = true;
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Auto-Switch: وقف التاسك وبدأ تاسك تاني لو الوقت قرب يخلص
                    |--------------------------------------------------------------------------
                    */

                    $autoSwitchBeforeMin = (int)$CONFIG['AUTO_SWITCH_BEFORE_MIN'];

                    if (
                        $autoSwitchBeforeMin > 0 &&
                        $remainingMin <= $autoSwitchBeforeMin &&
                        empty($state['tasks'][$taskId]['autoSwitchedSent'])
                    ) {
                        debugLog("تبديل تلقائي: فاضل {$remainingMin} دقيقة على التاسك {$taskId}");

                        $stopped = stopTimer($COOKIE_FILE, $taskId);

                        if ($stopped) {
                            // سجل السيشن القديمة عشان ميبعتش تنبيه وقف مكرر
                            $oldSessionKey = buildSessionKey($taskId, $today, $currentStartedAt);
                            $state['countedSessions'][$oldSessionKey] = true;
                            $state['tasks'][$taskId]['autoSwitchedSent'] = true;
                            unset($state['pendingTimerStop']);

                            $nextTask = findNextAvailableTask($COOKIE_FILE, $taskId, $today);

                            if ($nextTask) {
                                $started = startTimer($COOKIE_FILE, $nextTask['taskId']);

                                if ($started) {
                                    $msg  = "🔄 *تبديل تلقائي للتاسك*\n\n";
                                    $msg .= "⏹️ *التاسك اللي وقف:* {$taskTitle}\n";
                                    $msg .= "▫️ السبب: فاضل " . formatMinutes(max(0, $remainingMin)) . " على التقدير\n\n";
                                    $msg .= "▶️ *التاسك الجديد:* {$nextTask['title']}\n";
                                    $msg .= "🎯 التقدير: " . formatMinutes($nextTask['estimation']) . "\n";
                                    $msg .= "⏳ المستهلك: " . formatMinutes($nextTask['spent']) . "\n";
                                    $msg .= "📉 المتبقي: " . formatMinutes($nextTask['remaining']);

                                    sendTelegram($msg, $CONFIG);
                                    summaryLog("🔄 تبديل تلقائي: {$taskId} → {$nextTask['taskId']}");

                                    desktopNotify("Auto Switch", "اتبدل لتاسك: {$nextTask['title']}");

                                    // حدّث state بالتاسك الجديد عشان الـ run الجاي يشوفه صح
                                    $state['lastActiveTaskId'] = $nextTask['taskId'];
                                    $state['lastActiveMin'] = 0;
                                    $state['lastActiveDate'] = $today;
                                    $state['lastActiveStartedAt'] = date('Y-m-d H:i:s');
                                    $state['tasks'][$nextTask['taskId']]['title'] = $nextTask['title'];
                                } else {
                                    $msg  = "⚠️ *تم إيقاف التاسك تلقائياً*\n\n";
                                    $msg .= "⏹️ *التاسك:* {$taskTitle}\n";
                                    $msg .= "▫️ السبب: فاضل " . formatMinutes(max(0, $remainingMin)) . " على التقدير\n\n";
                                    $msg .= "❌ فشل تشغيل التاسك الجديد: {$nextTask['title']}";

                                    sendTelegram($msg, $CONFIG);

                                    $state['lastActiveTaskId'] = null;
                                    $state['lastActiveMin'] = 0;
                                    $state['lastActiveDate'] = null;
                                    $state['lastActiveStartedAt'] = null;
                                }
                            } else {
                                $msg  = "⚠️ *تم إيقاف التاسك تلقائياً*\n\n";
                                $msg .= "⏹️ *التاسك:* {$taskTitle}\n";
                                $msg .= "▫️ السبب: فاضل " . formatMinutes(max(0, $remainingMin)) . " على التقدير\n\n";
                                $msg .= "❌ *مفيش تاسك تاني متاح في الشهر المالي الحالي!*\n";
                                $msg .= "محتاج تشغل تاسك يدوي.";

                                sendTelegram($msg, $CONFIG);

                                $state['lastActiveTaskId'] = null;
                                $state['lastActiveMin'] = 0;
                                $state['lastActiveDate'] = null;
                                $state['lastActiveStartedAt'] = null;
                            }
                        } else {
                            debugLog("فشل إيقاف التايمر للتاسك {$taskId}، هحاول تاني في التشغيل الجاي");
                        }
                    }
                }
            }

            debugLog("===== نهاية فحص تقدير التاسك =====");

            /*
        |--------------------------------------------------------------------------
        | Save State
        |--------------------------------------------------------------------------
        */

            $state = maybeSendThursdayReport($state, $COOKIE_FILE, $CONFIG, $today);
            $state = maybeSendMonthEndReport($state, $COOKIE_FILE, $CONFIG, $today);

            pruneOldState($state);
            saveState($STATE_FILE, $state);

            logErpWorkSummary($COOKIE_FILE, $today, $CONFIG['WEEKLY_TARGET_MIN']);
        } while (false); // تشغيل مرة واحدة، والـ break/continue فوق بتوقف التنفيذ بدري

        debugLog("=========== نهاية تشغيل السكريبت ===========");
    } catch (Exception $e) {

        summaryLog("حصل خطأ: " . $e->getMessage());
    } finally {

        // Release lock
        if (isset($lockFp) && $lockFp) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }
