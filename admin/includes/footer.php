<?php // admin/includes/footer.php ?>
</div><!-- /#admin-wrapper -->
<?php $admin_asset = $admin_asset ?? 'assets/'; ?>

<?php include_once __DIR__ . '/scripts.php'; ?>
<?php if (!empty($page_scripts)) { echo $page_scripts; } ?>
<?php $asset = $asset ?? '../assets/'; ?>
<script src="<?= $asset ?>js/emsp-fixes.js"></script>
</body>
</html>


