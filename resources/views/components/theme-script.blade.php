{{-- Must run before first paint, so it is inlined in <head> ahead of @vite. --}}
<script>
    (function () {
        var stored = localStorage.getItem('qp-theme');

        if (stored === 'light') {
            document.documentElement.dataset.theme = 'light';
        }

        window.qpTheme = {
            toggle: function () {
                var root = document.documentElement;
                var next = root.dataset.theme === 'light' ? 'dark' : 'light';

                if (next === 'light') {
                    root.dataset.theme = 'light';
                } else {
                    delete root.dataset.theme;
                }

                localStorage.setItem('qp-theme', next);
            },
        };
    })();
</script>
