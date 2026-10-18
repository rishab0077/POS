document.addEventListener("DOMContentLoaded", function () {
    setInterval(checkOrderUpdates, orderSyncTime);
});

function markAsPrepared(orderId) {
    showLoader();
    $.ajax({
        type: "POST",
        url: markAsPreparedRoute,
        headers: {
            "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
        },
        data: {
            orderId: orderId,
        },
        success: function (response) {
            console.log(response);
            if (response.status === "success") {
                $("#order" + orderId).remove();
            } else {
                alert("Something went wrong");
            }
        },
        error: function (error) {
            console.error("Error marking order as prepared:", error);
            alert(kotErrorMessage(error, "Unable to mark order as prepared."));
        },
        complete: function () {
            hideLoader();
        },
    });
}
function markAsClosed(orderId) {
    showLoader();
    $.ajax({
        type: "POST",
        url: markAsClosedRoute,
        headers: {
            "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
        },
        data: {
            orderId: orderId,
        },
        success: function (response) {
            console.log(response);
            if (response.status === "success") {
                $("#order" + orderId).remove();
            } else {
                alert("Something went wrong");
            }
        },
        error: function (error) {
            console.error("Error marking order as closed:", error);
            alert(kotErrorMessage(error, "Unable to mark order as closed."));
        },
        complete: function () {
            hideLoader();
        },
    });
}

function kotErrorMessage(error, fallback) {
    if (error.responseJSON && error.responseJSON.message) {
        return error.responseJSON.message;
    }

    if (error.responseJSON && error.responseJSON.error) {
        return error.responseJSON.error;
    }

    if (error.responseJSON && error.responseJSON.errors) {
        return Object.values(error.responseJSON.errors).flat().join("\n");
    }

    if (error.status === 419) {
        return "Your session expired. Please refresh and try again.";
    }

    return fallback;
}

function checkOrderUpdates() {
    if (document.querySelector('details[open] [data-order-cancellation], dialog[open] [data-order-cancellation], [data-add-one]:disabled')) return;
    $.ajax({
        url: checkOrderUpdatesRoute,
        method: "GET",
        dataType: "json",
        success: function (response) {
            if (response.status === "success" && Array.isArray(response.orders) &&
                orderState(response.orders) !== orderState(getVisibleOrders())) {
                window.location.reload();
            }
        },
        error: function (error) {
            console.error("Error checking for updates:", error);
        },
    });
}

function getVisibleOrders() {
    return $(".order-item")
        .map(function () {
            return { id: Number(this.id.replace("order", "")), status: this.getAttribute("data-order-status"), item_count: Number(this.getAttribute('data-order-quantity')) };
        })
        .get();
}

function orderState(orders) {
    return JSON.stringify(orders.map(order => [Number(order.id), order.status, Number(order.item_count || 0)]).sort((a, b) => a[0] - b[0]));
}
