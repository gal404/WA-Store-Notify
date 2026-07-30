(function () {
    'use strict';

    // הוספת נוסח (וריאנט) — כפתור "+ נוסח נוסף"
    document.addEventListener('click', function (e) {
        if (!e.target.classList.contains('wsn-add-variant')) return;
        var scope = e.target.closest('.wsn-tpl-row, .wsn-card, form');
        var wrap = scope && scope.querySelector('.wsn-variants');
        if (!wrap) return;
        var last = wrap.querySelector('textarea');
        var ta = document.createElement('textarea');
        ta.name = last ? last.name : 'variants[]';
        ta.rows = 3;
        ta.dir = 'rtl';
        wrap.appendChild(ta);
        ta.focus();
    });

    // תבניות — פתיחה/סגירה של שורה מכווצת
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest ? e.target.closest('.wsn-tpl-toggle') : null;
        if (!toggle) return;
        var body = toggle.parentElement.querySelector('.wsn-tpl-body');
        if (!body) return;
        var opening = body.hidden;
        body.hidden = !opening;
        toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
        var caret = toggle.querySelector('.wsn-tpl-caret');
        if (caret) { caret.textContent = opening ? '▾' : '▸'; }
    });

    // תבניות — הצגת סטטוסים מוסתרים
    document.addEventListener('click', function (e) {
        if (!e.target.classList.contains('wsn-show-hidden')) return;
        var grp = document.querySelector('.wsn-hidden-status');
        if (grp) { grp.hidden = false; }
        e.target.style.display = 'none';
    });

    // תבניות — עדכון חיווי מופעל/כבוי בזמן סימון
    document.addEventListener('change', function (e) {
        if (!e.target.matches || !e.target.matches('.wsn-tpl-enable input')) return;
        var row = e.target.closest('.wsn-tpl-row');
        var state = row && row.querySelector('.wsn-tpl-state');
        if (!state) return;
        state.className = 'wsn-tpl-state ' + (e.target.checked ? 'is-on' : 'is-off');
        state.textContent = e.target.checked ? '✅' : '⚪';
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
                '<div class="wsn-modal-head">' +
                '<h2>' + (events.length > 1 ? 'סיבות לשינויים בהזמנה' : 'סיבת השינוי בהזמנה') + '</h2>' +
                '<p class="description">התנועות נשמרו. בחירת סיבה תאפשר בהמשך לשלוח ללקוח הודעה מדויקת על השינוי.</p>' +
                '</div>' +
                '<div class="wsn-modal-body">' + rows + '</div>' +
                '<div class="wsn-modal-actions">' +
                '<button type="button" class="button button-primary wsn-modal-save">שמור</button>' +
                '<button type="button" class="button wsn-modal-later">אחר כך</button>' +
                '<span class="wsn-modal-msg" style="color:#b32d2e;margin-inline-start:8px"></span>' +
                '</div></div>';
            document.body.appendChild(wrap);

            // סגירה ב-ESC (מנקה את המאזין כדי לא לדלוף)
            function closeModal() {
                document.removeEventListener('keydown', onKey);
                wrap.remove();
            }
            function onKey(e) {
                if (e.key === 'Escape' || e.keyCode === 27) { closeModal(); }
            }
            document.addEventListener('keydown', onKey);

            // "אחר" חושף שדה טקסט חופשי
            wrap.addEventListener('change', function (e) {
                if (!e.target.classList.contains('wsn-reason')) return;
                var other = e.target.parentElement.querySelector('.wsn-reason-other');
                other.hidden = e.target.value !== 'other';
            });
            wrap.querySelector('.wsn-modal-later').addEventListener('click', closeModal);
            wrap.querySelector('.wsn-modal-save').addEventListener('click', function () {
                var btn = this, msg = wrap.querySelector('.wsn-modal-msg');
                btn.disabled = true;
                msg.textContent = '';
                var rows = wrap.querySelectorAll('.wsn-modal-row');
                var extra = {};
                rows.forEach(function (row) {
                    var id = row.dataset.eventId;
                    var code = row.querySelector('.wsn-reason').value;
                    var text = row.querySelector('.wsn-reason-other').value;
                    extra['reasons[' + id + '][code]'] = code;
                    extra['reasons[' + id + '][text]'] = text;
                });
                post('wsn_save_item_reasons', extra).then(function (d) {
                    // check_ajax_referer שנכשל מחזיר -1 (JSON תקין) — לכן בודקים תוכן,
                    // וגם ש-saved מכסה את כל השורות, ורק אז מרעננים.
                    if (!d || !d.success || !d.data || d.data.saved < rows.length) {
                        msg.textContent = (d && d.data && d.data.message) ? d.data.message : 'שמירה נכשלה — נסה שוב';
                        btn.disabled = false;
                        return;
                    }
                    closeModal();
                    location.reload(); // מרענן את טבלת התנועות במטא-בוקס
                }).catch(function () {
                    msg.textContent = 'שגיאת רשת — נסה שוב';
                    btn.disabled = false;
                });
            });
        }

        var pendingInFlight = false;
        function checkPending() {
            // מונע מרוץ: שתי קריאות חופפות (טעינה + ajaxComplete) לא יבנו שני מודאלים
            if (pendingInFlight || document.querySelector('.wsn-modal-backdrop')) { return; }
            pendingInFlight = true;
            post('wsn_pending_item_events', {}).then(function (d) {
                if (d && d.success && d.data.events.length && !document.querySelector('.wsn-modal-backdrop')) {
                    buildModal(d.data.events);
                }
            }).catch(function () { /* שקט — לא מפריעים לעריכת ההזמנה */ })
              .then(function () { pendingInFlight = false; });
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

        // ---- מחיקת תנועה מהיומן ----
        eventsRoot.addEventListener('click', function (e) {
            var del = e.target.closest ? e.target.closest('.wsn-ev-del') : null;
            if (!del) { return; }
            e.preventDefault();
            if (!window.confirm('למחוק את התנועה מהיומן? הפעולה אינה הפיכה.')) { return; }
            del.disabled = true;
            var id = del.dataset.id;
            post('wsn_delete_item_event', { event_id: id }).then(function (d) {
                if (!d || !d.success) {
                    del.disabled = false;
                    window.alert((d && d.data) ? d.data : 'מחיקה נכשלה');
                    return;
                }
                var row = eventsRoot.querySelector('tr[data-event-id="' + id + '"]');
                if (row) { row.remove(); }
                // אם התנועה הייתה גם ברשימת "שינויים שטרם דווחו" — להסיר משם
                var chk = eventsRoot.querySelector('.wsn-ev-check[value="' + id + '"]');
                if (chk) { var li = chk.closest('li'); if (li) { li.remove(); } }
            }).catch(function () {
                del.disabled = false;
                window.alert('שגיאת רשת');
            });
        });

        // ---- עריכת/הגדרת סיבה של תנועה קיימת ----
        eventsRoot.addEventListener('change', function (e) {
            if (!e.target.classList || !e.target.classList.contains('wsn-reason-sel')) { return; }
            var other = e.target.parentElement.querySelector('.wsn-reason-other-txt');
            if (other) { other.hidden = e.target.value !== 'other'; }
        });
        eventsRoot.addEventListener('click', function (e) {
            var t = e.target;
            var cell = t.closest ? t.closest('.wsn-reason-cell') : null;
            if (!cell) { return; }
            var view = cell.querySelector('.wsn-reason-view');
            var editor = cell.querySelector('.wsn-reason-editor');
            if (t.classList.contains('wsn-reason-edit')) {
                e.preventDefault();
                view.hidden = true; editor.hidden = false;
            } else if (t.classList.contains('wsn-reason-cancel')) {
                e.preventDefault();
                editor.hidden = true; view.hidden = false;
            } else if (t.classList.contains('wsn-reason-save-edit')) {
                e.preventDefault();
                var sel = cell.querySelector('.wsn-reason-sel');
                var otherInput = cell.querySelector('.wsn-reason-other-txt');
                t.disabled = true;
                post('wsn_edit_item_reason', {
                    event_id: cell.dataset.eventId,
                    code: sel.value,
                    text: otherInput ? otherInput.value : ''
                }).then(function (d) {
                    t.disabled = false;
                    if (!d || !d.success) { window.alert((d && d.data) ? d.data : 'שמירה נכשלה'); return; }
                    var pill = view.querySelector('.wsn-pill');
                    if (pill) {
                        pill.className = 'wsn-pill wsn-pill-order';
                        pill.textContent = (d.data && d.data.label) ? d.data.label : sel.options[sel.selectedIndex].text;
                    }
                    editor.hidden = true; view.hidden = false;
                }).catch(function () { t.disabled = false; window.alert('שגיאת רשת'); });
            }
        });

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
                    composeMsg.textContent = 'נוסף לאישור ✔';
                    location.reload();
                }).catch(function (e) { composeMsg.textContent = 'שגיאה: ' + e.message; btn.disabled = false; });
            });
        }
    }

    // ---- מסך "הודעות ממתינות" — אישור/עריכה/מחיקה של טיוטות ----
    var pendingRoot = document.querySelector('.wsn-pending');
    if (pendingRoot) {
        var pendingBar = pendingRoot.querySelector('.wsn-pending-msg');

        function pPost(action, data) {
            var body = new URLSearchParams({ action: action, nonce: WSN.nonce });
            Object.keys(data || {}).forEach(function (k) {
                if (Array.isArray(data[k])) { data[k].forEach(function (v) { body.append(k + '[]', v); }); }
                else { body.append(k, data[k]); }
            });
            return fetch(WSN.ajax, { method: 'POST', body: body }).then(function (r) { return r.json(); });
        }

        function removeCard(card) {
            card.remove();
            if (!pendingRoot.querySelector('.wsn-draft')) { location.reload(); }
        }
        function dropCard(card) { if (card && card.parentNode) { card.remove(); } }

        function delayOf(d, id) {
            var v = (d && d.data && d.data.delays) ? d.data.delays[String(id)] : 0;
            v = parseInt(v, 10);
            return isNaN(v) ? 0 : v;
        }

        // הופך כרטיס שאושר לכרטיס "הושהתה" עם ספירה יורדת עד השליחה לשרת
        function markScheduled(card, seconds) {
            var actions = card.querySelector('.wsn-draft-actions');
            var bodyEl = card.querySelector('.wsn-draft-body');
            if (bodyEl) { bodyEl.readOnly = true; }
            card.classList.add('wsn-draft-scheduled');
            if (!actions) { return; }
            if (seconds <= 0) {
                actions.innerHTML = '<span class="wsn-draft-sent">✔ נשלחה לשרת</span>';
                setTimeout(function () { dropCard(card); }, 3000);
                return;
            }
            var end = Date.now() + seconds * 1000;
            actions.innerHTML = '<span class="wsn-draft-timer">⏳ ההודעה הושהתה — נשלחת בעוד <b>--:--</b> דק׳</span>';
            var b = actions.querySelector('b');
            var iv = setInterval(function () {
                var rem = Math.max(0, Math.round((end - Date.now()) / 1000));
                var mm = Math.floor(rem / 60), ss = rem % 60;
                b.textContent = (mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
                if (rem <= 0) {
                    clearInterval(iv);
                    actions.innerHTML = '<span class="wsn-draft-sent">✔ נשלחה לשרת</span>';
                    setTimeout(function () { dropCard(card); }, 5000);
                }
            }, 1000);
        }

        pendingRoot.addEventListener('click', function (e) {
            var t = e.target;
            var card = t.closest ? t.closest('.wsn-draft') : null;

            if (t.classList.contains('wsn-draft-send') && card) {
                var msg = card.querySelector('.wsn-draft-msg');
                t.disabled = true; msg.textContent = 'שולח…';
                pPost('wsn_draft_approve', { ids: [card.dataset.id], body: card.querySelector('.wsn-draft-body').value }).then(function (d) {
                    if (!d || !d.success) { msg.textContent = 'שגיאה: ' + ((d && d.data) || 'נכשל'); t.disabled = false; return; }
                    markScheduled(card, delayOf(d, card.dataset.id));
                }).catch(function () { msg.textContent = 'שגיאת רשת'; t.disabled = false; });

            } else if (t.classList.contains('wsn-draft-discard') && card) {
                if (!window.confirm('למחוק את ההודעה? היא לא תישלח.')) { return; }
                var msg2 = card.querySelector('.wsn-draft-msg');
                t.disabled = true; msg2.textContent = 'מוחק…';
                pPost('wsn_draft_discard', { ids: [card.dataset.id] }).then(function (d) {
                    if (!d || !d.success) { msg2.textContent = 'שגיאה: ' + ((d && d.data) || 'נכשל'); t.disabled = false; return; }
                    removeCard(card);
                }).catch(function () { msg2.textContent = 'שגיאת רשת'; t.disabled = false; });

            } else if (t.classList.contains('wsn-approve-all')) {
                var cards = Array.prototype.slice.call(pendingRoot.querySelectorAll('.wsn-draft'));
                if (!cards.length) { return; }
                if (!window.confirm('לשלוח את כל ' + cards.length + ' ההודעות?')) { return; }
                t.disabled = true; pendingBar.textContent = 'שולח הכל…';
                var saves = cards.map(function (c) {
                    return pPost('wsn_draft_save', { id: c.dataset.id, body: c.querySelector('.wsn-draft-body').value });
                });
                Promise.all(saves).then(function () {
                    return pPost('wsn_draft_approve', { ids: cards.map(function (c) { return c.dataset.id; }) });
                }).then(function (d) {
                    if (!d || !d.success) { pendingBar.textContent = 'שגיאה: ' + ((d && d.data) || 'נכשל'); t.disabled = false; return; }
                    pendingBar.textContent = 'אושרו ' + (d.data.approved || cards.length) + ' הודעות — לפי הסדר, עם השהיה בין הודעות לאותו לקוח';
                    if (t.parentNode) { t.remove(); }
                    cards.forEach(function (c) { markScheduled(c, delayOf(d, c.dataset.id)); });
                }).catch(function () { pendingBar.textContent = 'שגיאת רשת'; t.disabled = false; });
            }
        });
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
