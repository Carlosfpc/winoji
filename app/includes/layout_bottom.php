    </main>
    </div><!-- /.main-column -->
</div><!-- /.app-root -->
<script>
document.getElementById('logout-btn').addEventListener('click', async () => {
    await fetch('<?= APP_URL ?>/app/api/auth.php?action=logout', { method: 'POST' });
    window.location.href = '<?= APP_URL ?>?page=login';
});
</script>
<script>
const toggle = document.getElementById('sidebar-toggle');
const sidebar = document.querySelector('.sidebar');
if (toggle && sidebar) {
    toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    document.addEventListener('click', e => {
        if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
            sidebar.classList.remove('open');
        }
    });
}
</script>
</body>
</html>
