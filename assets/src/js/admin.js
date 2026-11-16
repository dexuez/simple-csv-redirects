document.addEventListener('DOMContentLoaded', function () {
    const drop = document.querySelector('.scr-drop');

    if (drop) {
        const input = drop.querySelector('input[type="file"]');
        const name = drop.querySelector('.scr-drop-name');

        input.addEventListener('change', function () {
            const has = input.files.length > 0;
            name.textContent = has ? input.files[0].name : name.dataset.empty;
            drop.classList.toggle('has-file', has);
        });

        ['dragenter', 'dragover'].forEach(function (e) {
            drop.addEventListener(e, function () {
                drop.classList.add('is-over');
            });
        });
        ['dragleave', 'drop'].forEach(function (e) {
            drop.addEventListener(e, function () {
                drop.classList.remove('is-over');
            });
        });
    }

    const all = document.getElementById('scr-all');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('.scr-check').forEach(function (box) {
                box.checked = all.checked;
            });
        });
    }

    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
});
