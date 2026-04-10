<?php

namespace App\Utils;

class ProcessingModal
{
    public static function render(string $modalId = 'vnvProcessingModal', array $options = []): string
    {
        $title = $options['title'] ?? 'Processing...';
        $message = $options['message'] ?? 'Please wait, this may take a few seconds.';
        $spinnerClass = $options['spinner_class'] ?? 'text-primary';

        $titleEscaped = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $messageEscaped = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $spinnerClassEscaped = htmlspecialchars($spinnerClass, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="modal fade" id="{$modalId}" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-default-title="{$titleEscaped}" data-default-message="{$messageEscaped}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center py-5">
                <div class="spinner-border {$spinnerClassEscaped} mb-4" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <h5 class="fw-semibold mb-2" data-role="processing-title">{$titleEscaped}</h5>
                <p class="text-muted mb-0" data-role="processing-message">{$messageEscaped}</p>
            </div>
        </div>
    </div>
</div>
HTML;
    }

    public static function script(string $modalId = 'vnvProcessingModal'): string
    {
        $modalIdEscaped = addslashes($modalId);

        return <<<HTML
<script>
window.VnvProcessingModal = window.VnvProcessingModal || (function () {
    const instances = {};

    function ensureInstance(id) {
        if (!instances[id]) {
            const element = document.getElementById(id);
            if (!element) {
                console.warn('[VnvProcessingModal] Modal with id "' + id + '" not found.');
                return null;
            }
            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                console.warn('[VnvProcessingModal] Bootstrap Modal is required.');
                return null;
            }
            instances[id] = {
                element,
                modal: new bootstrap.Modal(element, { backdrop: 'static', keyboard: false })
            };
        }
        return instances[id];
    }

    function updateContent(element, options) {
        if (!options) {
            return;
        }
        const titleNode = element.querySelector('[data-role="processing-title"]');
        const messageNode = element.querySelector('[data-role="processing-message"]');

        if (options.title !== undefined && titleNode) {
            titleNode.textContent = options.title;
        }
        if (options.message !== undefined && messageNode) {
            messageNode.textContent = options.message;
        }
    }

    return {
        show(id, options) {
            const instance = ensureInstance(id);
            if (!instance) return;
            updateContent(instance.element, options);
            instance.modal.show();
        },
        hide(id, reset = true) {
            const instance = ensureInstance(id);
            if (!instance) return;
            instance.modal.hide();
            if (reset) {
                this.reset(id);
            }
        },
        reset(id) {
            const instance = ensureInstance(id);
            if (!instance) return;
            updateContent(instance.element, {
                title: instance.element.dataset.defaultTitle || 'Processing...',
                message: instance.element.dataset.defaultMessage || 'Please wait, this may take a few seconds.'
            });
        }
    };
})();

document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('{$modalIdEscaped}')) {
        VnvProcessingModal.reset('{$modalIdEscaped}');
    }
});
</script>
HTML;
    }
}

