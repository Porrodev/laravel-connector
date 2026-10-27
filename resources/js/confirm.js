(function () {
    if (window.porroConfirm) return;
    window.porroConfirm = true;

    // Forms carrying data-porro-confirm ask in the page's <dialog id="porro-confirm">
    // first. The dialog's buttons carry their own classes for danger and safe
    // actions, so each panel styles it its own way.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var dialog = document.getElementById('porro-confirm');

        if (!form.dataset || !form.dataset.porroConfirm || form.dataset.porroConfirmed) return;
        if (!dialog || typeof dialog.showModal !== 'function') return;

        var ask;
        try { ask = JSON.parse(form.dataset.porroConfirm); } catch (e) { return; }

        event.preventDefault();
        event.stopPropagation();

        var ok = dialog.querySelector('[data-porro-ok]');
        dialog.querySelector('[data-porro-title]').textContent = ask.title;
        dialog.querySelector('[data-porro-body]').textContent = ask.body;
        ok.textContent = ask.button;
        ok.className = ask.danger ? ok.dataset.danger : ok.dataset.safe;

        dialog.returnValue = '';
        dialog.onclose = function () {
            if (dialog.returnValue !== 'ok') return;
            form.dataset.porroConfirmed = '1';
            if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
        };
        dialog.showModal();
        dialog.querySelector('[data-porro-cancel]').focus();
    }, true);
})();
