// Simple sidebar toggle for mobile.
(function () {
    var toggle = document.querySelector('.menu-toggle');
    var toggleLabel = toggle ? toggle.querySelector('.menu-label') : null;
    var toggleIcon = toggle ? toggle.querySelector('.menu-icon') : null;
    var backdrop = document.querySelector('.sidebar-backdrop');
    var header = document.querySelector('.header');
    var sidebar = document.querySelector('.sidebar');
    var closeBtn = document.querySelector('.sidebar-close');
    if (!toggle) return;

    var mqMobile = window.matchMedia('(max-width: 960px)');

    function setLabel() {
        var isMobile = mqMobile.matches;
        var isOpen = isMobile ? document.body.classList.contains('sidebar-open') : !document.body.classList.contains('sidebar-collapsed');
        if (toggleLabel) toggleLabel.textContent = isOpen ? 'Tutup' : 'Menu';
        if (toggleIcon) toggleIcon.textContent = '';
    }

    function syncHeaderHeight() {
        if (!header) return;
        var height = header.offsetHeight || 64;
        document.documentElement.style.setProperty('--header-height', height + 'px');
    }

    function handleToggle() {
        var isMobile = mqMobile.matches;
        if (isMobile) {
            var open = document.body.classList.toggle('sidebar-open');
            // jangan biarkan class collapsed aktif di mobile
            if (open) document.body.classList.remove('sidebar-collapsed');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
            // pastikan mode mobile tidak tersisa
            document.body.classList.remove('sidebar-open');
        }
        setLabel();
    }

    toggle.addEventListener('click', handleToggle);
    if (closeBtn) {
        closeBtn.addEventListener('click', function(){
            document.body.classList.remove('sidebar-open');
            setLabel();
        });
    }
    if (sidebar) {
        sidebar.addEventListener('click', function(e){
            if (!mqMobile.matches) return;
            var link = e.target.closest('a');
            if (link) {
                document.body.classList.remove('sidebar-open');
                setLabel();
            }
        });
    }
    if (backdrop) {
        backdrop.addEventListener('click', function(){
            document.body.classList.remove('sidebar-open');
            setLabel();
        });
    }
    mqMobile.addEventListener('change', function(){
        setLabel();
        syncHeaderHeight();
    });
    if (window.ResizeObserver && header) {
        var ro = new ResizeObserver(function(){
            syncHeaderHeight();
        });
        ro.observe(header);
    }
    window.addEventListener('resize', syncHeaderHeight);
    window.addEventListener('load', syncHeaderHeight);
    setLabel();
    syncHeaderHeight();
})();
