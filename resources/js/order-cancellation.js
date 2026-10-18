document.addEventListener("submit", async (event) => {
    const form = event.target;
    if (!form.matches("[data-order-cancellation]")) return;
    event.preventDefault();
    const error = form.querySelector("[data-cancellation-error]");
    const button = form.querySelector('button[type="submit"]');
    if (button.disabled) return;
    error.textContent = "";
    if (!window.confirm(`Cancel ${form.dataset.kot}? Notify the kitchen if its ticket was already printed.`)) return;
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
        window.location.reload();
    } catch {
        error.textContent = "Unable to confirm cancellation. Refresh to check the order before trying again.";
    } finally {
        button.disabled = false;
    }
});
