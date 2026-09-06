(function () {
    'use strict';
    function initialize() {
        document.querySelectorAll('[data-fg-design-review]').forEach(function (review) {
            var source = review.querySelector('[data-fg-design-source]');
            if (!source) { return; }
            var card = review.closest('[data-fg-change-card]') || review.closest('.fg-card');
            var status = review.querySelector('[data-fg-design-status]');
            var approve = card && card.querySelector('[data-fg-approve], button[name="decision"][value="approved"]');
            var validation = card && card.querySelector('[name="design_validation"]');
            if (approve) { approve.disabled = true; }
            if (validation) { validation.value = ''; }
            try {
                if (!window.wp || !wp.blocks || !wp.blockLibrary || !window.FamesDesignValidation) { throw new Error('The installed Gutenberg validation packages could not be loaded.'); }
                if (!wp.blocks.getBlockType('core/paragraph')) { wp.blockLibrary.registerCoreBlocks(); }
                var result = window.FamesDesignValidation.validate(source.value);
                if (!result.valid) { throw new Error(result.errors.map(function (error) { return error.path + ': ' + error.message; }).join(' ')); }
                if (!validation || !review.dataset.contentDigest) { throw new Error('Reload this review before approving.'); }
                validation.value = review.dataset.contentDigest;
                status.textContent = 'Gutenberg Validation Passed — ' + result.blockCount + ' editable block(s).';
                if (approve) { approve.disabled = false; }
            } catch (error) {
                status.textContent = 'Gutenberg Validation Failed — ' + error.message + ' Ask the client to revise the design, or reject this request.';
            }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initialize); }
    else { initialize(); }
})();
