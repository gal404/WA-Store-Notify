(function () {
    'use strict';

    // הוספת נוסח (וריאנט) — כפתור "+ נוסח נוסף"
    document.addEventListener('click', function (e) {
        if (!e.target.classList.contains('wsn-add-variant')) return;
        var wrap = e.target.closest('.wsn-card, form').querySelector('.wsn-variants');
        if (!wrap) return;
        var last = wrap.querySelector('textarea');
        var ta = document.createElement('textarea');
        ta.name = last ? last.name : 'variants[]';
        ta.rows = 2;
        ta.dir = 'rtl';
        wrap.appendChild(ta);
        ta.focus();
    });

    // "שלח לי לבדיקה" — נוסף לתור בעדיפות עליונה, ואז נבדק בזמן אמת עד שנשלח/נכשל
    // (במקום "נוסף לתור" ואז לבדוק ביומן ידנית) — אימות מיידי שהכול עובד מקצה לקצה.
    document.addEventListener('click', function (e) {
        if (!e.target.classList.contains('wsn-send-test')) return;
        var btn = e.target;
        var status = btn.parentElement.querySelector('.wsn-test-status');
        btn.disabled = true;
        status.textContent = 'שולח…';
        fetch(WSN.ajax, {
            method: 'POST',
            body: new URLSearchParams({ action: 'wsn_send_test', nonce: WSN.nonce, tpl_key: btn.dataset.tplKey })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { status.textContent = 'שגיאה: ' + (d.data || ''); btn.disabled = false; return; }
                pollTestStatus(d.data.id, btn, status, 0);
            })
            .catch(function (err) { status.textContent = 'שגיאה: ' + err.message; btn.disabled = false; });
    });

    function pollTestStatus(id, btn, status, elapsedMs) {
        if (elapsedMs > 90000) { status.textContent = 'עדיין ממתין לגשר… בדוק ביומן ההודעות בעוד רגע.'; btn.disabled = false; return; }
        fetch(WSN.ajax, {
            method: 'POST',
            body: new URLSearchParams({ action: 'wsn_test_status', nonce: WSN.nonce, id: id })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { status.textContent = 'שגיאה בבדיקת סטטוס'; btn.disabled = false; return; }
                var s = d.data.status;
                if (s === 'sent') { status.textContent = '✔ נשלח בהצלחה'; btn.disabled = false; return; }
                if (s === 'failed' || s === 'expired' || s === 'cancelled') {
                    status.textContent = 'לא נשלח: ' + (d.data.last_error || s); btn.disabled = false; return;
                }
                status.textContent = s === 'claimed' ? 'הגשר שולח עכשיו…' : 'ממתין לגשר…';
                setTimeout(function () { pollTestStatus(id, btn, status, elapsedMs + 2000); }, 2000);
            })
            .catch(function () {
                setTimeout(function () { pollTestStatus(id, btn, status, elapsedMs + 2000); }, 2000);
            });
    }

    // "שלח מיידית" ביומן — מדלג בתור להודעה בודדת ומראה חי מתי היא נשלחת בפועל
    document.addEventListener('click', function (e) {
        if (!e.target.classList.contains('wsn-send-now-row')) return;
        var btn = e.target;
        var status = btn.parentElement.querySelector('.wsn-send-now-status');
        var id = btn.dataset.id;
        btn.disabled = true;
        status.textContent = 'מדלג בתור…';
        fetch(WSN.ajax, {
            method: 'POST',
            body: new URLSearchParams({ action: 'wsn_send_now_single', nonce: WSN.nonce, id: id })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { status.textContent = 'שגיאה: ' + (d.data || ''); btn.disabled = false; return; }
                pollTestStatus(id, btn, status, 0);
            })
            .catch(function (err) { status.textContent = 'שגיאה: ' + err.message; btn.disabled = false; });
    });

    // חישוב קהל יעד להערכת קמפיין (חי)
    var countBtn = document.getElementById('wsn-count-btn');
    if (countBtn) {
        countBtn.addEventListener('click', function () {
            var out = document.getElementById('wsn-count-result');
            out.textContent = 'מחשב…';
            var body = new URLSearchParams({
                action: 'wsn_audience_count',
                nonce: WSN.nonce,
                min_orders: (document.getElementById('wsn-min-orders') || {}).value || 0,
                last_order_days: (document.getElementById('wsn-last-days') || {}).value || 0
            });
            fetch(WSN.ajax, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        out.textContent = d.data.count + ' נמענים — ' + d.data.estimate;
                    } else {
                        out.textContent = 'שגיאה: ' + (d.data || '');
                    }
                })
                .catch(function (err) { out.textContent = 'שגיאה: ' + err.message; });
        });
    }
})();
