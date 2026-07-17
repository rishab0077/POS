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
    var lastOrderId = getLastOrderId();
    console.log("Last Order Id:", lastOrderId);
    $.ajax({
        url: checkOrderUpdatesRoute,
        method: "GET",
        dataType: "json",
        data: {
            lastOrderId: lastOrderId,
        },
        success: function (response) {
            console.log(response);
            if (
                response.status === "success" &&
                response.hasNewOrders === true
            ) {
                window.location.reload();
            } else {
                console.log("No new orders found");
            }
        },
        error: function (error) {
            console.error("Error checking for updates:", error);
        },
    });
}

function getLastOrderId() {
    var ids = $(".order-item")
        .map(function () {
            return parseInt(this.id.replace("order", ""), 10);
        })
        .filter(function () {
            return Number.isFinite(this);
        })
        .get();

    return ids.length > 0 ? Math.max(...ids) : 0;
}
