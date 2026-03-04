(function() {
    var nav = document.querySelector('.sidebar-nav');
    if (!nav) return;
    var STORAGE_KEY = 'admin_sidebar_order';

    var links = nav.querySelectorAll('a');
    links.forEach(function(a) {
        var href = (a.getAttribute('href') || '').replace(/^\.\.\//, '');
        var id = href.replace(/\.php.*$/, '').replace(/[^a-zA-Z0-9_]/g, '') || 'home';
        if (href === '../chat.php' || href.indexOf('chat.php') !== -1) id = 'back';
        a.dataset.id = id;
        if (id !== 'back') a.draggable = true;
    });

    if (!nav.id) nav.id = 'sidebarNav';

    function applyOrder() {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (!saved) return;
        try {
            var order = JSON.parse(saved);
            var items = {};
            nav.querySelectorAll('a[data-id]').forEach(function(a) { items[a.dataset.id] = a; });
            order.forEach(function(id) { if (items[id]) nav.appendChild(items[id]); });
            nav.querySelectorAll('a[data-id]').forEach(function(a) {
                if (order.indexOf(a.dataset.id) === -1) nav.appendChild(a);
            });
        } catch(e) {}
    }

    function saveOrder() {
        var order = [];
        nav.querySelectorAll('a[data-id]').forEach(function(a) { order.push(a.dataset.id); });
        localStorage.setItem(STORAGE_KEY, JSON.stringify(order));
    }

    var dragItem = null;

    nav.addEventListener('dragstart', function(e) {
        var a = e.target.closest('a[draggable="true"]');
        if (!a) return;
        dragItem = a;
        a.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    nav.addEventListener('dragend', function() {
        if (dragItem) dragItem.classList.remove('dragging');
        nav.querySelectorAll('.drag-over').forEach(function(el) { el.classList.remove('drag-over'); });
        dragItem = null;
    });

    nav.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var target = e.target.closest('a[draggable="true"]');
        if (!target || target === dragItem) return;
        nav.querySelectorAll('.drag-over').forEach(function(el) { el.classList.remove('drag-over'); });
        target.classList.add('drag-over');
    });

    nav.addEventListener('dragleave', function(e) {
        var target = e.target.closest('a[draggable="true"]');
        if (target) target.classList.remove('drag-over');
    });

    nav.addEventListener('drop', function(e) {
        e.preventDefault();
        var target = e.target.closest('a[draggable="true"]');
        if (!target || !dragItem || target === dragItem) return;
        target.classList.remove('drag-over');
        var rect = target.getBoundingClientRect();
        if (e.clientY < rect.top + rect.height / 2) {
            nav.insertBefore(dragItem, target);
        } else {
            nav.insertBefore(dragItem, target.nextSibling);
        }
        saveOrder();
    });

    applyOrder();
})();
