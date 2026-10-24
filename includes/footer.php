<?php
/**
 * Shared Application Footer Layout Partial
 */

declare(strict_types=1);
?>
    </div> <!-- /.content-body -->

    <footer class="app-footer text-center py-3 border-top border-secondary-subtle bg-dark mt-auto">
        <small class="text-secondary">
            &copy; <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?>. All rights reserved. Built with Core PHP & MySQL.
        </small>
    </footer>
</main> <!-- /.app-main -->
</div> <!-- /.app-wrapper -->

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
