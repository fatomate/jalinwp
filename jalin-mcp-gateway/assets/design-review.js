(function () {
    'use strict';
    function initialize() {
        /** @type {NodeListOf<HTMLElement>} */
        var reviews = document.querySelectorAll('[data-fg-design-review]');
        reviews.forEach(function (review) {
            var source = review.querySelector('[data-fg-design-source]');
            var status = review.querySelector('[data-fg-design-status]');
            if (!(source instanceof HTMLTextAreaElement) || !status) { return; }
            var card = review.closest('[data-fg-change-card]') || review.closest('.fg-card');
            var approve = card && card.querySelector('[data-fg-approve], button[name="decision"][value="approved"]');
            var validation = card && card.querySelector('[name="design_validation"]');
            if (approve instanceof HTMLButtonElement || approve instanceof HTMLInputElement) { approve.disabled = true; }
            if (validation instanceof HTMLInputElement) { validation.value = ''; }
            try {
                if (!window.wp || !wp.blocks || !wp.blockLibrary || !window.JalinDesignValidation) { throw new Error('The installed Gutenberg validation packages could not be loaded.'); }
                if (!wp.blocks.getBlockType('core/paragraph')) { wp.blockLibrary.registerCoreBlocks(); }
                var result = window.JalinDesignValidation.validate(source.value);
                if (!result.valid) { throw new Error(result.errors.map(function (error) { return error.path + ': ' + error.message; }).join(' ')); }
                if (!(validation instanceof HTMLInputElement) || !review.dataset.contentDigest) { throw new Error('Reload this review before approving.'); }
                validation.value = review.dataset.contentDigest;
                status.textContent = 'Gutenberg Validation Passed — ' + result.blockCount + ' editable block(s).';
                if (approve instanceof HTMLButtonElement || approve instanceof HTMLInputElement) { approve.disabled = false; }
            } catch (error) {
                status.textContent = 'Gutenberg Validation Failed — ' + (error instanceof Error ? error.message : 'The installed validator did not provide an error message.') + ' Ask the client to revise the design, or reject this request.';
            }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initialize); }
    else { initialize(); }
})();
