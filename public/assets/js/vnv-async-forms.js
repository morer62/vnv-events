(function () {
  'use strict';

  function absoluteUrl(value, fallback) {
    try { return new URL(value || fallback, window.location.href).toString(); }
    catch (_) { return fallback || window.location.href; }
  }

  function setBusy(form, busy, message) {
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
      if (busy) {
        button.dataset.vnvOriginalLabel = button.tagName === 'INPUT' ? button.value : button.innerHTML;
        if (button.tagName === 'INPUT') button.value = message;
        else button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' + message;
      } else if (button.dataset.vnvOriginalLabel) {
        if (button.tagName === 'INPUT') button.value = button.dataset.vnvOriginalLabel;
        else button.innerHTML = button.dataset.vnvOriginalLabel;
        delete button.dataset.vnvOriginalLabel;
      }
      button.disabled = busy;
    });
  }

  function notify(message, type) {
    if (window.bootstrap && document.getElementById('alertToast')) {
      var toast = document.getElementById('alertToast');
      var body = toast.querySelector('.toast-body');
      if (body) body.textContent = message;
      toast.classList.toggle('text-bg-danger', type === 'error');
      new bootstrap.Toast(toast).show();
      return;
    }
    window.alert(message);
  }

  async function submit(form, options) {
    options = options || {};
    if (form.dataset.vnvSubmitting === '1') return false;
    if (!form.checkValidity()) { form.reportValidity(); return false; }

    form.dataset.vnvSubmitting = '1';
    var waiting = options.waitingMessage || form.dataset.asyncWaiting || 'Saving...';
    setBusy(form, true, waiting);

    try {
      var formAction = form.getAttribute('action') || window.location.href;
      var formMethod = form.getAttribute('method') || 'POST';
      var response = await fetch(absoluteUrl(formAction, window.location.href), {
        method: formMethod.toUpperCase(),
        body: new FormData(form),
        credentials: 'same-origin',
        redirect: 'follow',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html,application/json'}
      });
      if (!response.ok) throw new Error('The server returned HTTP ' + response.status + '.');

      var contentType = response.headers.get('content-type') || '';
      if (contentType.includes('application/json')) {
        var data = await response.json();
        if (data.success === false) throw new Error(data.message || 'The operation could not be completed.');
        if (data.redirect) options.successUrl = data.redirect;
      } else {
        var html = await response.text();
        if (/Application error|<title>[^<]*error/i.test(html)) throw new Error('The server could not finish the operation.');
      }

      var finalUrl = new URL(response.url, window.location.href);
      var currentUrl = new URL(window.location.href);
      if (response.redirected && finalUrl.pathname === currentUrl.pathname && finalUrl.search === currentUrl.search && form.dataset.asyncAllowSameRedirect !== '1') {
        throw new Error('The server returned to the same form. Review the validation message and try again.');
      }

      var successUrl = options.successUrl || form.dataset.asyncSuccessUrl || (response.redirected ? response.url : '');
      if (!successUrl) throw new Error('The operation returned without a confirmation redirect.');
      notify(options.successMessage || form.dataset.asyncSuccessMessage || 'Saved successfully.', 'success');
      window.location.assign(absoluteUrl(successUrl, response.url));
      return true;
    } catch (error) {
      form.dataset.vnvSubmitting = '0';
      form.dataset.submitting = '0';
      setBusy(form, false, waiting);
      notify(error && error.message ? error.message : 'Unable to complete the operation. Please try again.', 'error');
      return false;
    }
  }

  window.vnvAsyncSubmit = submit;
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-vnv-async]');
    if (!form) return;
    event.preventDefault();
    submit(form);
  });
})();
