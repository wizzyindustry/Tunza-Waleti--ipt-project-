// assets/js/main.js - Tunza Waleti Global Script

document.addEventListener('DOMContentLoaded', function () {
    const menuToggle = document.querySelector('.navigation .fa-list');
    const sidebar = document.querySelector('.side-bar');

    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside of it
        document.addEventListener('click', function (e) {
            if (!sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });
    }
});
