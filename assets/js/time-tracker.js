
document.addEventListener('DOMContentLoaded', function () {

    const timer = document.getElementById('timer');
    const idleTimer = document.getElementById('idle-timer');
    const idlePrompt = document.getElementById('idle-prompt');

    if (!timer || !idleTimer) {
        return;
    }

    // =====================================
    // 1. TIMER DATA
    // =====================================

    const status = timer.dataset.status;

    const totalSeconds = Number(timer.dataset.totalSeconds);
    const idleSeconds = Number(idleTimer.dataset.idleSeconds);

    const resumedAt = Date.parse(timer.dataset.resumedAt);
    const idleStartedAt = Date.parse(idleTimer.dataset.idleStartedAt);

    const IDLE_THRESHOLD = 5 * 60 * 1000;
    const ACTIVITY_SYNC_INTERVAL = 2000;

    let lastActivityAt =
        Date.parse(timer.dataset.lastActivityAt) || Date.now();

    let lastActivitySentAt = lastActivityAt;

    let activityRequestInProgress = false;
    let idleRequestInProgress = false;
    let rolloverRequestInProgress = false;

    // =====================================
    // 2. FORMAT TIMER
    // =====================================

    function formatTime(seconds) {

        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainingSeconds = seconds % 60;

        return (
            String(hours).padStart(2, '0') + ':' +
            String(minutes).padStart(2, '0') + ':' +
            String(remainingSeconds).padStart(2, '0')
        );
    }

    // =====================================
    // 3. UPDATE WORKING AND IDLE TIME
    // =====================================

    function updateTimers() {

        const now = Date.now();

        let workingTime = totalSeconds;
        let idleTime = idleSeconds;

        if (status === 'running' && Number.isFinite(resumedAt)) {
            workingTime += Math.max(
                0,
                Math.floor((now - resumedAt) / 1000)
            );
        }

        if (status === 'idle' && Number.isFinite(idleStartedAt)) {
            idleTime += Math.max(
                0,
                Math.floor((now - idleStartedAt) / 1000)
            );
        }

        timer.textContent = formatTime(workingTime);
        idleTimer.textContent = formatTime(idleTime);
    }

    // =====================================
    // 4. SEND REQUEST TO PHP
    // =====================================

    async function sendTimerAction(action) {

        const formData = new FormData();

        formData.append('timer_action', action);

        const response = await fetch('time-tracker.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });

        const result = await response.json();

        // Reload if the database completed a daily rollover.
        if (
            result.reload ||
            Number(result.rolled_over || 0) > 0
        ) {
            window.location.reload();
        }

        return result;
    }

    // =====================================
    // 5. CHECK DAILY ROLLOVER
    // =====================================

    async function checkDailyRollover() {

        if (rolloverRequestInProgress) {
            return;
        }

        rolloverRequestInProgress = true;

        try {

            await sendTimerAction('rollover');

        } catch (error) {

            console.error(
                'Unable to check daily rollover:',
                error
            );

        } finally {

            rolloverRequestInProgress = false;

        }
    }

    // =====================================
    // 6. RECORD ACTIVITY
    // =====================================

    async function recordActivity() {

        if (activityRequestInProgress) {
            return;
        }

        activityRequestInProgress = true;

        try {

            const result = await sendTimerAction('activity');

            if (result.success) {
                lastActivitySentAt = Date.now();
            }

        } catch (error) {

            console.error(
                'Unable to record activity:',
                error
            );

        } finally {

            activityRequestInProgress = false;

        }
    }

    // =====================================
    // 7. DETECT IDLE
    // =====================================

    async function markIdle() {

        if (idleRequestInProgress) {
            return;
        }

        idleRequestInProgress = true;

        try {

            const result = await sendTimerAction('mark_idle');

            if (result.success) {
                window.location.reload();
                return;
            }

        } catch (error) {

            console.error(
                'Unable to mark session as idle:',
                error
            );

        } finally {

            idleRequestInProgress = false;

        }
    }

    function checkIdle() {

        if (status !== 'running') {
            return;
        }

        const now = Date.now();

        const allowedTime =
            IDLE_THRESHOLD + ACTIVITY_SYNC_INTERVAL + 1500;

        if (now - lastActivityAt >= allowedTime) {
            markIdle();
        }
    }

    // =====================================
    // 8. HANDLE USER ACTIVITY
    // =====================================

    function handleActivity() {

        if (status === 'idle') {

            if (idlePrompt) {
                idlePrompt.hidden = false;
            }

            return;
        }

        if (status !== 'running') {
            return;
        }

        lastActivityAt = Date.now();

        if (
            lastActivityAt - lastActivitySentAt >=
            ACTIVITY_SYNC_INTERVAL
        ) {
            recordActivity();
        }
    }

    // =====================================
    // 9. LISTEN FOR USER ACTIVITY
    // =====================================

    const activityEvents = [
        'mousemove',
        'mousedown',
        'keydown',
        'scroll',
        'touchstart',
        'pointerdown'
    ];

    activityEvents.forEach(function (eventName) {

        document.addEventListener(
            eventName,
            handleActivity,
            { passive: true }
        );

    });

    // =====================================
    // 10. START TIMER UPDATES
    // =====================================

    updateTimers();

    setInterval(function () {

        updateTimers();
        checkIdle();

    }, 1000);

    // =====================================
    // 11. DAILY ROLLOVER CHECK
    // =====================================

    // Check the database every 15 seconds.
    setInterval(checkDailyRollover, 15000);

    // Check again when the worker returns to the page.
    document.addEventListener('visibilitychange', function () {

        if (!document.hidden) {
            checkDailyRollover();
            checkIdle();
        }

    });

    window.addEventListener('focus', function () {

        checkDailyRollover();
        checkIdle();

    });

    // Check immediately after loading the page.
    checkDailyRollover();

});
