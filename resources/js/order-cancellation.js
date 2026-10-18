document.addEventListener("submit", async (event) => {
    const form = event.target;
    if (!form.matches("[data-order-cancellation]")) return;
    event.preventDefault();
    const error = form.querySelector("[data-cancellation-error]");
    const button = form.querySelector('button[type="submit"]');
    if (button.disabled) return;
    error.textContent = "";
    if (!form.dataset.item && !window.confirm(`Cancel ${form.dataset.kot}? Notify the kitchen if its ticket was already printed.`)) return;
    button.disabled = true;
    try {
        const response = await fetch(form.action, {
            method: "POST",
            headers: { Accept: "application/json" },
            body: new FormData(form),
        });
        const data = await response.json();
        if (!response.ok) {
            error.textContent = Object.values(data.errors || {}).flat().join("\n") || data.message || "Unable to cancel this order.";
            return;
        }
        window.dispatchEvent(new Event('order-cancellation-recorded'));
        window.location.reload();
    } catch {
        error.textContent = "Unable to confirm cancellation. Refresh to check the order before trying again.";
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-cancel-one], [data-close-cancellation], [data-add-one]');
    if (!button) return;
    const control = button.closest('[data-sent-item]');
    const dialog = control.querySelector('dialog');
    if (button.hasAttribute('data-cancel-one')) {
        dialog.showModal();
        return;
    }
    if (button.hasAttribute('data-close-cancellation')) {
        dialog.close();
        return;
    }
    if (button.disabled || !window.confirm(`Add one ${button.dataset.name} as a new KOT?`)) return;
    button.disabled = true;
    try {
        const response = await fetch(button.dataset.url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ source: button.dataset.source, tableId: Number(button.dataset.tableId),
                isPickUpOrder: false, billTable: false, paymentMethod: 'cash',
                order: { orderItems: [{ id: Number(button.dataset.menuId), quantity: 1 }] } }),
        });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') {
            window.alert(Object.values(data.errors || {}).flat().join('\n') || data.message || 'Unable to add item.');
            return;
        }
        window.location.reload();
    } catch {
        window.alert('Unable to confirm the new KOT. Refresh and check before trying again.');
    } finally {
        button.disabled = false;
    }
});
