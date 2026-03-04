        </div><!-- /.page-content -->
    </main>
    
    <!-- Earth受け取りアニメーション用のオーバーレイ -->
    <div class="earth-animation-overlay" id="earth-animation" style="display: none;">
        <div class="earth-animation-content">
            <div class="coin-rain"></div>
            <div class="earth-received-amount">
                <span class="plus">+</span>
                <span class="amount" id="earth-received-amount">0</span>
                <span class="unit">Earth</span>
            </div>
            <div class="new-balance">
                <span class="label"><?= __('your_earth') ?>:</span>
                <span class="value" id="earth-new-balance">0</span>
            </div>
        </div>
    </div>
    
    <!-- トースト通知コンテナ -->
    <div class="toast-container" id="toast-container"></div>
    
    <script src="<?= asset('js/common.js') ?>"></script>
    <script src="<?= asset('js/layout.js') ?>"></script>
    <?php if (isset($extraJs)): ?>
    <?php foreach ((array)$extraJs as $js): ?>
    <script src="<?= asset('js/' . $js) ?>"></script>
    <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
