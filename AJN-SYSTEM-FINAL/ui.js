document.addEventListener('DOMContentLoaded', () => {
  // Load focused modal styles without changing the existing site stylesheet.
  if (!document.querySelector('link[data-payment-fixes]')) {
    const styles = document.createElement('link');
    styles.rel = 'stylesheet';
    styles.href = 'css/payment-fixes.css';
    styles.dataset.paymentFixes = 'true';
    document.head.appendChild(styles);
  }

  document.querySelectorAll('.notification-wrap').forEach(wrap => {
    const bell = wrap.querySelector('.notification-bell');
    const panel = wrap.querySelector('.notification-panel');
    if (!bell || !panel) return;

    bell.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      const open = bell.getAttribute('aria-expanded') !== 'true';
      bell.setAttribute('aria-expanded', String(open));
      panel.hidden = !open;
    });

    panel.addEventListener('click', event => event.stopPropagation());
    document.addEventListener('click', event => {
      if (!wrap.contains(event.target)) {
        panel.hidden = true;
        bell.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        panel.hidden = true;
        bell.setAttribute('aria-expanded', 'false');
      }
    });
  });

  document.querySelectorAll('.sidebar-toggle').forEach(toggle => {
    const sidebar = toggle.closest('.sidebar');
    if (!sidebar) return;
    const setOpen = open => {
      sidebar.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
    };
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    sidebar.querySelectorAll('.nav-menu a').forEach(link => link.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') setOpen(false);
    });
    document.addEventListener('click', event => {
      if (!sidebar.contains(event.target)) setOpen(false);
    });
  });

  document.querySelectorAll('a[href="logout.php"]').forEach(link => {
    link.addEventListener('click', event => {
      if (!window.confirm('Are you sure you want to log out?')) event.preventDefault();
    });
  });
  document.querySelectorAll('[data-confirm]').forEach(element => {
    element.addEventListener(element.tagName === 'FORM' ? 'submit' : 'click', event => {
      if (!window.confirm(element.dataset.confirm)) event.preventDefault();
    });
  });
  document.querySelectorAll('.alert.success, .alert-box.success, .alert.warning, .alert-box.warning').forEach(message => {
    window.setTimeout(() => { message.classList.add('is-expired'); }, 2000);
  });

  const paymentDialog = document.getElementById('paymentDialog');
  if (paymentDialog) {
    const paymentId = document.getElementById('paymentOrderId');
    const paymentNumber = document.getElementById('paymentOrderNumber');
    const paymentTotal = document.getElementById('paymentOrderTotal');
    const gcashPanel = paymentDialog.querySelector('#gcashQrPanel');
    const paymentMethods = Array.from(paymentDialog.querySelectorAll('input[name="payment_method"]'));
    const syncPaymentMethodUi = selectedMethod => {
      if (gcashPanel) gcashPanel.hidden = selectedMethod !== 'GCash';
      if (selectedMethod === 'PayPal' && paymentId?.value) {
        window.location.href = 'payment.php?id=' + encodeURIComponent(paymentId.value);
      }
    };
    const openPayment = button => {
      if (paymentId) paymentId.value = button.dataset.orderId;
      if (paymentNumber) paymentNumber.textContent = '# ' + button.dataset.orderNumber;
      if (paymentTotal) paymentTotal.textContent = button.dataset.orderTotal;
      const selectedMethod = paymentMethods.find(radio => radio.checked)?.value || '';
      syncPaymentMethodUi(selectedMethod);
      paymentDialog.showModal();
    };
    document.querySelectorAll('[data-payment-open]').forEach(button => button.addEventListener('click', () => openPayment(button)));
    paymentDialog.querySelectorAll('[data-payment-close]').forEach(button => button.addEventListener('click', () => paymentDialog.close()));
    paymentDialog.addEventListener('click', event => {
      if (event.target === paymentDialog) paymentDialog.close();
    });
    paymentMethods.forEach(radio => radio.addEventListener('change', () => syncPaymentMethodUi(radio.value)));
    syncPaymentMethodUi(paymentMethods.find(radio => radio.checked)?.value || '');

    const reopenOrderId = new URLSearchParams(window.location.search).get('pay_order');
    if (reopenOrderId) {
      const reopenButton = document.querySelector(`[data-payment-open][data-order-id="${reopenOrderId}"]`);
      if (reopenButton) openPayment(reopenButton);
    }
  }

  const detailsDialog = document.createElement('dialog');
  detailsDialog.className = 'order-details-dialog';
  detailsDialog.setAttribute('aria-labelledby', 'orderDetailsTitle');
  detailsDialog.innerHTML = '<button type="button" class="dialog-close" aria-label="Close order details">×</button><h2 id="orderDetailsTitle"></h2><dl><dt>Order ID</dt><dd data-detail="number"></dd><dt>Date</dt><dd data-detail="date"></dd><dt>Customer</dt><dd data-detail="customer"></dd><dt>Style</dt><dd data-detail="style"></dd><dt>Sizes</dt><dd data-detail="sizes"></dd><dt>Total</dt><dd data-detail="total"></dd><dt>Status</dt><dd data-detail="status"></dd><dt>Payment</dt><dd data-detail="payment"></dd></dl><div class="order-details-actions"><a class="secondary-btn" data-detail-url href="#">View full details</a><a class="submit-btn" data-edit-url href="#" hidden>Edit order</a></div>';
  document.body.appendChild(detailsDialog);
  detailsDialog.querySelector('.dialog-close').addEventListener('click', () => detailsDialog.close());
  detailsDialog.addEventListener('click', event => {
    if (event.target === detailsDialog) detailsDialog.close();
  });
  document.querySelectorAll('[data-details-open]').forEach(button => button.addEventListener('click', () => {
    detailsDialog.querySelector('#orderDetailsTitle').textContent = `Order #${button.dataset.orderNumber} Details`;
    detailsDialog.querySelector('[data-detail="number"]').textContent = `#${button.dataset.orderNumber}`;
    detailsDialog.querySelector('[data-detail="date"]').textContent = button.dataset.orderDate || '—';
    detailsDialog.querySelector('[data-detail="customer"]').textContent = button.dataset.customer || 'Your order';
    detailsDialog.querySelector('[data-detail="style"]').textContent = button.dataset.shirtType || '—';
    detailsDialog.querySelector('[data-detail="sizes"]').textContent = button.dataset.sizes || '—';
    detailsDialog.querySelector('[data-detail="total"]').textContent = `₱ ${button.dataset.total || '0.00'}`;
    detailsDialog.querySelector('[data-detail="status"]').textContent = button.dataset.status || '—';
    detailsDialog.querySelector('[data-detail="payment"]').textContent = button.dataset.payment || '—';
    const fullDetailsLink = detailsDialog.querySelector('[data-detail-url]');
    fullDetailsLink.href = button.dataset.detailsUrl || '#';
    const editLink = detailsDialog.querySelector('[data-edit-url]');
    if (button.dataset.editUrl) {
      editLink.href = button.dataset.editUrl;
      editLink.hidden = false;
    } else {
      editLink.hidden = true;
    }
    detailsDialog.showModal();
  }));

  const designDialog = document.createElement('dialog');
  designDialog.className = 'design-lightbox';
  designDialog.setAttribute('aria-label', 'Order design preview');
  designDialog.innerHTML = '<button type="button" class="design-lightbox-close" aria-label="Close image preview">&times;</button><img alt="Order design enlarged">';
  document.body.appendChild(designDialog);
  const dialogImage = designDialog.querySelector('img');
  const closeDesignDialog = () => designDialog.close();
  designDialog.querySelector('.design-lightbox-close').addEventListener('click', closeDesignDialog);
  designDialog.addEventListener('click', event => {
    if (event.target === designDialog) closeDesignDialog();
  });
  document.querySelectorAll('.order-thumb, .popular-card > img').forEach(image => {
    const showImage = () => {
      dialogImage.src = image.currentSrc || image.src;
      dialogImage.alt = image.alt.startsWith('View ') ? image.alt.replace(/^View /, '').replace(/ design larger$/, ' design') : 'Enlarged order design';
      designDialog.showModal();
    };
    image.addEventListener('click', showImage);
    image.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        showImage();
      }
    });
  });

  document.querySelectorAll('.size-quantity-group').forEach(group => {
    const form = group.closest('form');
    const quantityInput = form?.querySelector('.requested-quantity');
    const sizeInputs = Array.from(group.querySelectorAll('.size-quantity-input'));
    if (!form || !quantityInput || !sizeInputs.length) return;
    const totalOutput = group.querySelector('.sizeQuantityTotal');
    const quantityOutput = group.querySelector('.requestedQuantityTotal');
    const assignedTotal = () => sizeInputs.reduce((sum, input) => sum + (parseInt(input.value, 10) || 0), 0);
    const updateSizeLimits = () => {
      const requested = parseInt(quantityInput.value, 10) || 0;
      if (quantityOutput) quantityOutput.textContent = requested;
      if (totalOutput) totalOutput.textContent = assignedTotal();
      sizeInputs.forEach(input => {
        const others = sizeInputs.reduce((sum, other) => sum + (other === input ? 0 : (parseInt(other.value, 10) || 0)), 0);
        input.max = Math.max(0, requested - others);
      });
    };
    quantityInput.addEventListener('input', updateSizeLimits);
    sizeInputs.forEach(input => input.addEventListener('input', () => {
      const requested = parseInt(quantityInput.value, 10) || 0;
      const others = sizeInputs.reduce((sum, other) => sum + (other === input ? 0 : (parseInt(other.value, 10) || 0)), 0);
      const maxForThisSize = Math.max(0, requested - others);
      if ((parseInt(input.value, 10) || 0) > maxForThisSize) input.value = maxForThisSize;
      updateSizeLimits();
    }));
    form.addEventListener('submit', event => {
      const requested = parseInt(quantityInput.value, 10) || 0;
      if (requested < 1 || requested > 100 || assignedTotal() !== requested) {
        event.preventDefault();
        alert('The size quantities must add up to the total quantity. Increase the total quantity if you need to assign more shirts.');
      }
    });
    updateSizeLimits();
  });
});
