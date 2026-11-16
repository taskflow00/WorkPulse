
document.addEventListener('DOMContentLoaded', function () {

    const timer = document.getElementById('timer');
    const idleTimer = document.getElementById('idle-timer');
    const idlePrompt = document.getElementById('idle-prompt');

    if (!timer || !idleTimer) {
        return;
    }

    // =====================================
    // 1. TIMER DATA AND CONFIGURATION
    // =====================================

    const status = timer.dataset.status;

    const totalSeconds = Number(timer.dataset.totalSeconds);
    const idleSeconds = Number(idleTimer.dataset.idleSeconds);

    const resumedAt = Date.parse(timer.dataset.resumedAt);
    const idleStartedAt = Date.parse(idleTimer.dataset.idleStartedAt);

    // Read the idle timeout configured in Supabase.
    // PHP passes this value through the timer's data attribute.
    const configuredIdleSeconds =
        Number(timer.dataset.idleTimeoutSeconds);

    // Use 300 seconds (5 minutes) if the setting is missing or invalid.
    const IDLE_THRESHOLD =
        (Number.isFinite(configuredIdleSeconds) &&
         configuredIdleSeconds > 0
            ? configuredIdleSeconds
            : 300) * 1000;

    // Synchronize activity with the database every 2 seconds.
    const ACTIVITY_SYNC_INTERVAL = 2000;

    let lastActivityAt =
        Date.parse(timer.dataset.lastActivityAt) || Date.now();

    let lastActivitySentAt = lastActivityAt;

    let awayStartedAt = null;

    let activityRequestInProgress = false;
    let idleRequestInProgress = false;
    let rolloverRequestInProgress = false;

    // Extra time to account for activity synchronization and network delays.
    const IDLE_BUFFER =
        ACTIVITY_SYNC_INTERVAL + 1500;


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

        // Continue counting working time while running.
        if (status === 'running' && Number.isFinite(resumedAt)) {

            workingTime += Math.max(
                0,
                Math.floor((now - resumedAt) / 1000)
            );

        }

        // Continue counting idle time while idle.
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

        const responseText = await response.text();

        let result;

        try {

            result = JSON.parse(responseText);

        } catch (error) {

            console.error(
                'Invalid response from time-tracker.php:',
                responseText
            );

            throw new Error(
                'The server returned an invalid response. Your session may have expired.'
            );

        }

        // Reload if the user's session is no longer authorized.
        if (response.status === 401) {

            window.location.reload();

            return result;
        }

        // Handle unsuccessful HTTP responses.
        if (!response.ok) {

            throw new Error(
                result.message || 'Unable to process timer action.'
            );

        }

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
                error.message
            );

        } finally {

            rolloverRequestInProgress = false;

        }
    }


    // =====================================
    // 6. RECORD USER ACTIVITY
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
                error.message
            );

        } finally {

            activityRequestInProgress = false;

        }
    }


    // =====================================
    // 7. MARK SESSION AS IDLE
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

            console.error(
                'Failed to mark session as idle:',
                result.message || result
            );

        } catch (error) {

            console.error(
                'Unable to mark session as idle:',
                error.message
            );

        } finally {

            idleRequestInProgress = false;

        }
    }


    // =====================================
    // 8. CHECK IDLE TIME
    // =====================================

    function checkIdle() {

        if (status !== 'running') {
            return;
        }

        const allowedTime = IDLE_THRESHOLD + IDLE_BUFFER;

        if (Date.now() - lastActivityAt >= allowedTime) {

            markIdle();

        }
    }


    // =====================================
    // 9. DETECT WINDOW / TAB CHANGES
    // =====================================

    function handleWindowAway() {

        if (status !== 'running') {
            return;
        }

        // Record only the first event when leaving the page.
        if (awayStartedAt === null) {

            awayStartedAt = Date.now();
            lastActivityAt = awayStartedAt;

        }
    }


    function handleWindowReturn() {

        if (status !== 'running' || awayStartedAt === null) {
            return;
        }

        const awayDuration = Date.now() - awayStartedAt;

        awayStartedAt = null;

        const allowedTime = IDLE_THRESHOLD + IDLE_BUFFER;

        // Check whether the worker was away long enough to be idle.
        if (awayDuration >= allowedTime) {

            checkIdle();
            return;

        }

        // The worker returned before reaching the idle threshold.
        lastActivityAt = Date.now();
    }


    // =====================================
    // 10. SHOW IDLE MODAL
    // =====================================

    function showIdleModal() {

        if (!idlePrompt || !idlePrompt.hidden) {
            return;
        }

        idlePrompt.hidden = false;
        document.body.style.overflow = 'hidden';
    }


    // Show the modal immediately if the session is already idle.
    if (status === 'idle') {

        showIdleModal();

    }


    // =====================================
    // 11. HANDLE USER ACTIVITY
    // =====================================

    function handleActivity() {

        if (status === 'idle') {

            showIdleModal();
            return;

        }

        if (status !== 'running') {
            return;
        }

        // Ignore activity while the page is hidden or unfocused.
        if (document.hidden || !document.hasFocus()) {
            return;
        }

        lastActivityAt = Date.now();

        // Send activity to the database when needed.
        if (
            lastActivityAt - lastActivitySentAt >=
            ACTIVITY_SYNC_INTERVAL
        ) {

            recordActivity();

        }
    }


    // =====================================
    // 12. LISTEN FOR USER ACTIVITY
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
    // 13. LISTEN FOR WINDOW / TAB CHANGES
    // =====================================

    // Detect when the browser tab becomes hidden or visible.
    document.addEventListener('visibilitychange', function () {

        if (document.hidden) {

            handleWindowAway();

        } else {

            handleWindowReturn();
            checkDailyRollover();
            checkIdle();

        }

    });


    // Detect when the browser window loses focus.
    window.addEventListener('blur', function () {

        handleWindowAway();

    });


    // Detect when the browser window regains focus.
    window.addEventListener('focus', function () {

        handleWindowReturn();
        checkDailyRollover();
        checkIdle();

    });


    // =====================================
    // 14. START TIMER UPDATES
    // =====================================

    updateTimers();

    setInterval(function () {

        updateTimers();
        checkIdle();

    }, 1000);


    // =====================================
    // 15. DAILY ROLLOVER CHECK
    // =====================================

    setInterval(checkDailyRollover, 15000);

    // Run an initial rollover check.
    checkDailyRollover();

});
