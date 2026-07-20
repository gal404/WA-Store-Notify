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
