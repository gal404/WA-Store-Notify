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

    // תוכן הודעה ביומן — לחיצה/Enter פותחת וסוגרת את הטקסט המלא
    document.addEventListener('click', function (e) {
        var body = e.target.closest && e.target.closest('.wsn-body');
        if (body) { body.classList.toggle('is-open'); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var body = e.target.closest && e.target.closest('.wsn-body');
        if (!body) return;
        e.preventDefault();
        body.classList.toggle('is-open');
    });

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

    // ---- מודאל סיבות לתנועות פריטים (במסך עריכת הזמנה) ----
    var eventsRoot = document.querySelector('.wsn-events');
    if (eventsRoot) {
        var orderId = eventsRoot.dataset.orderId;
        var evNonce = eventsRoot.dataset.nonce;

        function post(action, extra) {
            var body = new URLSearchParams({ action: action, _wpnonce: evNonce, order_id: orderId });
            Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
            return fetch(WSN.ajax, { method: 'POST', body: body }).then(function (r) { return r.json(); });
        }

        function buildModal(events) {
            var wrap = document.createElement('div');
            wrap.className = 'wsn-modal-backdrop';
            var rows = events.map(function (ev) {
                var opts = Object.keys(ev.reasons).map(function (code) {
                    return '<option value="' + code + '">' + ev.reasons[code] + '</option>';
                }).join('');
                return '<div class="wsn-modal-row" data-event-id="' + ev.id + '">' +
                    '<div class="wsn-modal-desc">' + ev.description + '</div>' +
                    '<select class="wsn-reason">' + opts + '</select>' +
                    '<input type="text" class="wsn-reason-other" placeholder="פרט את הסיבה" hidden>' +
                    '</div>';
            }).join('');
            wrap.innerHTML =
                '<div class="wsn-modal" role="dialog" aria-modal="true" dir="rtl">' +
                '<h2>' + (events.length > 1 ? 'סיבות לשינויים בהזמנה' : 'סיבת השינוי בהזמנה') + '</h2>' +
                '<p class="description">התנועות נשמרו. בחירת סיבה תאפשר בהמשך לשלוח ללקוח הודעה מדויקת על השינוי.</p>' +
                rows +
                '<div class="wsn-modal-actions">' +
                '<button type="button" class="button button-primary wsn-modal-save">שמור</button>' +
                '<button type="button" class="button wsn-modal-later">אחר כך</button>' +
                '</div></div>';
            document.body.appendChild(wrap);

            // "אחר" חושף שדה טקסט חופשי
            wrap.addEventListener('change', function (e) {
                if (!e.target.classList.contains('wsn-reason')) return;
                var other = e.target.parentElement.querySelector('.wsn-reason-other');
                other.hidden = e.target.value !== 'other';
            });
            wrap.querySelector('.wsn-modal-later').addEventListener('click', function () { wrap.remove(); });
            wrap.querySelector('.wsn-modal-save').addEventListener('click', function () {
                var btn = this;
                btn.disabled = true;
                var extra = {};
                wrap.querySelectorAll('.wsn-modal-row').forEach(function (row) {
                    var id = row.dataset.eventId;
                    var code = row.querySelector('.wsn-reason').value;
                    var text = row.querySelector('.wsn-reason-other').value;
                    extra['reasons[' + id + '][code]'] = code;
                    extra['reasons[' + id + '][text]'] = text;
                });
                post('wsn_save_item_reasons', extra).then(function () {
                    wrap.remove();
                    location.reload(); // מרענן את טבלת התנועות במטא-בוקס
                }).catch(function () { btn.disabled = false; });
            });
        }

        function checkPending() {
            post('wsn_pending_item_events', {}).then(function (d) {
                if (d && d.success && d.data.events.length && !document.querySelector('.wsn-modal-backdrop')) {
                    buildModal(d.data.events);
                }
            }).catch(function () { /* שקט — לא מפריעים לעריכת ההזמנה */ });
        }

        // ווקומרס שומר/מוסיף/מסיר פריטים ב-AJAX. מאזינים לסיום הקריאות האלה
        // (jQuery זמין תמיד ב-wp-admin) במקום לנחש אירוע פנימי של ווקומרס.
        if (window.jQuery) {
            window.jQuery(document).ajaxComplete(function (_e, _xhr, settings) {
                var d = (settings && (settings.data || settings.url)) || '';
                if (typeof d !== 'string') return;
                if (d.indexOf('save_order_items') !== -1 ||
                    d.indexOf('add_order_item') !== -1 ||
                    d.indexOf('remove_order_item') !== -1) {
                    setTimeout(checkPending, 400); // אחרי שווקומרס סיים לרנדר מחדש
                }
            });
        }
        checkPending(); // גם בטעינת העמוד, אם נשארו תנועות בלי סיבה

        // ---- בניית הודעה מהשינויים שנבחרו ----
        var buildBtn = eventsRoot.querySelector('.wsn-compose-build');
        if (buildBtn) {
            var previewBox = eventsRoot.querySelector('.wsn-compose-preview');
            var bodiesBox = eventsRoot.querySelector('.wsn-compose-bodies');
            var composeMsg = eventsRoot.querySelector('.wsn-compose-msg');

            function selectedIds() {
                return Array.prototype.slice
                    .call(eventsRoot.querySelectorAll('.wsn-ev-check:checked'))
                    .map(function (c) { return c.value; });
            }

            buildBtn.addEventListener('click', function () {
                var ids = selectedIds();
                if (!ids.length) { composeMsg.textContent = 'לא נבחרו שינויים'; return; }
                var mode = (eventsRoot.querySelector('input[name=wsn_compose_mode]:checked') || {}).value || 'combined';
                composeMsg.textContent = 'בונה…';
                var extra = { mode: mode };
                ids.forEach(function (id, i) { extra['event_ids[' + i + ']'] = id; });
                post('wsn_compose_changes', extra).then(function (d) {
                    if (!d.success) { composeMsg.textContent = 'שגיאה: ' + (d.data || ''); return; }
                    composeMsg.textContent = '';
                    bodiesBox.innerHTML = '';
                    d.data.messages.forEach(function (m, i) {
                        var ta = document.createElement('textarea');
                        ta.rows = 4;
                        ta.dir = 'rtl';
                        ta.className = 'wsn-compose-body';
                        ta.value = m.body;
                        ta.dataset.ids = m.ids.join(',');
                        bodiesBox.appendChild(ta);
                    });
                    previewBox.hidden = false;
                }).catch(function (e) { composeMsg.textContent = 'שגיאה: ' + e.message; });
            });

            eventsRoot.querySelector('.wsn-compose-send').addEventListener('click', function () {
                var btn = this;
                var areas = eventsRoot.querySelectorAll('.wsn-compose-body');
                if (!areas.length) { return; }
                btn.disabled = true;
                composeMsg.textContent = 'מוסיף לתור…';
                var saveTpl = eventsRoot.querySelector('.wsn-save-template');
                var extra = {};
                areas.forEach(function (ta, i) {
                    extra['messages[' + i + '][body]'] = ta.value;
                    if (saveTpl && saveTpl.checked) { extra['messages[' + i + '][save_template]'] = '1'; }
                    ta.dataset.ids.split(',').forEach(function (id, j) {
                        extra['messages[' + i + '][ids][' + j + ']'] = id;
                    });
                });
                post('wsn_queue_changes', extra).then(function (d) {
                    if (!d.success) { composeMsg.textContent = 'שגיאה: ' + (d.data || ''); btn.disabled = false; return; }
                    composeMsg.textContent = 'נוסף לתור ✔';
                    location.reload();
                }).catch(function (e) { composeMsg.textContent = 'שגיאה: ' + e.message; btn.disabled = false; });
            });
        }
    }

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
