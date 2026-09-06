// Author: Pavan Vattikala

const noitemsContainer =
    '<tr id="noitems"><td colspan="3">No Items Selected <br> Select from Left Menu</td></tr>';

// Menu Function Start
function showMenu(categoryId) {
    $(".menu-items").addClass("hidden");
    $(".category button").removeClass("active");
    const menuItems = document.getElementById(categoryId);

    menuItems.classList.toggle("hidden");

    $("#" + categoryId + "-btn").addClass("active");
}

// Menu Function End

//------------------------------------------------------------------------------------------------------------------------------

// Order Table Functions Start

// render the order table
function renderOrderTable() {
    const orderItemsBody = $("#order-items-body");
    orderItemsBody.empty(); // Clear existing content
    var count = 0;

    // if no items are present in the order
    if (orderItems.length === 0) {
        orderItemsBody.append(noitemsContainer);
    } else {
        orderItems.forEach((item) => {
            const loyaltyControls = item.loyaltyEligible ? `
                <div class="mt-1 flex items-center gap-1 text-xs">
                    <button type="button" class="rounded bg-amber-500 px-2 py-1 text-white" onclick="redeemLoyalty(${item.id})">Redeem</button>
                    ${item.loyaltyRewardQuantity > 0 ? `<button type="button" class="rounded bg-gray-200 px-2 py-1" onclick="undoLoyalty(${item.id})">Undo</button><span class="font-semibold text-amber-800">Free × ${item.loyaltyRewardQuantity}</span>` : ''}
                </div>` : '';
            const tr = $(`
                            <tr>
                                <td>
                                    <button class="del-item" onclick="delItem(${item.id})">X</button>
                                    <span><span>${item.name}</span>${loyaltyControls}</span>
                                </td>
                                <td>
                                    <button class="qty-options remQty" onclick="remQty(${item.id})">-</button>
                                    <span id="qty">${item.quantity}</span>
                                    <button class="qty-options addQty" onclick="addQty(${item.id})">+</button>
                                </td>
                                <td>${Number(item.total).toFixed(2)}</td>
                            </tr>
                        `);
            orderItemsBody.append(tr);
            count++;
        });
    }

    $("#item-count").text(count);

    calculateTotal();
    $("input[type='text']").val("");
    $(".menu-items button").show();
}

// add item to order
function addItemToOrder(menuId) {
    playAudio();
    if ($("#noitems").length > 0) {
        $("#noitems").remove();
    }
    const menu = $("#" + menuId);
    const existingItem = orderItems.find((item) => item.id === menuId);

    if (existingItem) {
        existingItem.quantity++;
        updateItemTotal(existingItem);
    } else {
        const newItem = {
            id: menuId,
            name: menu.data("name") || menu.text().trim(),
            quantity: 1,
            price: Number(menu.data("price")),
            total: Number(menu.data("price")),
            loyaltyEligible: Number(menu.data("loyaltyEligible")) === 1,
            loyaltyRewardQuantity: 0,
        };
        orderItems.push(newItem);
    }
    renderOrderTable();
    scrollToTop();
}

// increase quantity of an item
function addQty(menuId) {
    const item = orderItems.find((item) => item.id === menuId);
    if (item) {
        item.quantity++;
        updateItemTotal(item);
        renderOrderTable();
    }
}
// decrease quantity of an item
function remQty(menuId) {
    const item = orderItems.find((item) => item.id === menuId);
    if (item) {
        if (item.quantity > 1) {
            item.quantity--;
            item.loyaltyRewardQuantity = Math.min(item.loyaltyRewardQuantity, item.quantity);
            updateItemTotal(item);
        } else {
            // Remove the item if quantity becomes 0
            orderItems.splice(orderItems.indexOf(item), 1);
        }
        renderOrderTable();
    }
}

// calculate total
function calculateTotal() {
    let total = 0;
    orderItems.forEach((item) => {
        total += Number(item.total);
    });

    const discount = Number($("#discount").text());
    const grandtotal = Number(total - discount);

    $("#grandtotal").text(grandtotal);
    $("#total").text(total.toFixed(2));
    $("#billing-total").text((payableCents(total + existingTableOrderTotal) / 100).toFixed(2));
    updateSplitAvailability();
    updateSplitRemainder(true);
}

function updateItemTotal(item) {
    item.total = (item.quantity - item.loyaltyRewardQuantity) * item.price;
}

function redeemLoyalty(menuId) {
    const item = orderItems.find((candidate) => candidate.id === menuId);
    if (!item || !item.loyaltyEligible || item.loyaltyRewardQuantity >= item.quantity) return;
    if (!confirm("Confirm that one completed physical stamp card has been collected.")) return;
    item.loyaltyRewardQuantity++;
    updateItemTotal(item);
    renderOrderTable();
}

function undoLoyalty(menuId) {
    const item = orderItems.find((candidate) => candidate.id === menuId);
    if (!item || item.loyaltyRewardQuantity <= 0) return;
    item.loyaltyRewardQuantity--;
    updateItemTotal(item);
    renderOrderTable();
}

function changeExistingLoyalty(detailId, delta, maximum) {
    const row = $(`[data-existing-loyalty="${detailId}"]`);
    const current = Number(row.find("[data-loyalty-count]").text());
    const quantity = Math.max(0, Math.min(current + delta, maximum));
    if (quantity === current) return;
    if (delta > 0 && !confirm("Confirm that one completed physical stamp card has been collected.")) return;

    $.ajax({
        url: loyaltyUpdateUrl.replace("__DETAIL__", detailId),
        type: "POST",
        data: { quantity },
        headers: { "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content") },
        success: function (response) {
            row.find("[data-loyalty-count]").text(response.quantity);
            existingTableOrderTotal = Number(response.table_total);
            $("#existing-order-total").text(existingTableOrderTotal.toFixed(2));
            calculateTotal();
        },
        error: function (error) {
            alert(billingErrorMessage(error, "Unable to update loyalty reward."));
        },
    });
}

function payableCents(subtotal, discount = 0) {
    const subtotalCents = Math.max(Math.round(Number(subtotal) * 100), 0);
    const discountCents = Math.max(Math.min(Math.round(Number(discount) * 100), subtotalCents), 0);
    const afterDiscountCents = subtotalCents - discountCents;
    const serviceCents = serviceChargeEnabled
        ? Math.round(afterDiscountCents * serviceChargeRate / 100)
        : 0;
    const grossCents = afterDiscountCents + serviceCents;

    return vatInclusive ? grossCents : grossCents + Math.round(grossCents * vatRate / 100);
}

function updateSplitAvailability() {
    const canSplit = Math.round(Number($("#billing-total").text()) * 100) >= 2;
    $("#split").prop("disabled", !canSplit)
        .closest("label")
        .toggleClass("opacity-50 cursor-not-allowed", !canSplit);

    if (!canSplit && $("#split").is(":checked")) {
        $("#cash").prop("checked", true);
        $("#split-payment-fields").addClass("hidden");
    }
}

function updateSplitRemainder(autoFillFirst = false) {
    if ($("#split-payment-fields").length === 0) return;

    const totalCents = Math.max(Math.round(Number($("#billing-total").text()) * 100), 0);
    let firstCents = Math.max(Math.round(Number($("#split-amount-1").val() || 0) * 100), 0);

    if (totalCents < 2) {
        $("#split-amount-1").val("");
        firstCents = 0;
    } else if (autoFillFirst === true && firstCents <= 0) {
        firstCents = Math.floor(totalCents / 2);
        $("#split-amount-1").val((firstCents / 100).toFixed(2));
    }

    const remainderCents = Math.max(totalCents - firstCents, 0);
    const invalidFirstAmount = totalCents >= 2 && firstCents >= totalCents;
    $("#split-amount-2").val((remainderCents / 100).toFixed(2));
    $("#split-payment-total")
        .toggleClass("text-red-700", invalidFirstAmount)
        .toggleClass("text-gray-600", !invalidFirstAmount)
        .text(invalidFirstAmount
            ? `First amount must be less than the bill total of NPR ${(totalCents / 100).toFixed(2)}.`
            : `Bill total: NPR ${(totalCents / 100).toFixed(2)} · Remaining: NPR ${(remainderCents / 100).toFixed(2)}`);
}

function syncSplitMethodOptions(changedId = null) {
    const $first = $("#split-method-1");
    const $second = $("#split-method-2");
    if (!$first.length || !$second.length) return;

    if ($first.val() === $second.val()) {
        const $target = changedId === "split-method-2" ? $first : $second;
        const otherValue = $target.is($first) ? $second.val() : $first.val();
        $target.val($target.find("option").filter((_, option) => option.value !== otherValue).first().val());
    }

    $first.find("option").prop("disabled", false).filter(`[value="${$second.val()}"]`).prop("disabled", true);
    $second.find("option").prop("disabled", false).filter(`[value="${$first.val()}"]`).prop("disabled", true);
}

function collectSplitPaymentsIfNeeded(paymentMethod) {
    if (paymentMethod !== "split") return [];

    updateSplitRemainder();
    const methods = [$("#split-method-1").val(), $("#split-method-2").val()];
    const amounts = [$("#split-amount-1").val(), $("#split-amount-2").val()];
    const totalCents = Math.round(Number($("#billing-total").text()) * 100);
    const firstCents = Math.round(Number(amounts[0] || 0) * 100);

    if (methods[0] === methods[1]) {
        alert("Split payment methods must be different.");
        return null;
    }

    if (firstCents >= totalCents) {
        alert(`First split amount must be less than the bill total of NPR ${(totalCents / 100).toFixed(2)}.`);
        return null;
    }

    if (amounts.some((amount) => Math.round(Number(amount || 0) * 100) <= 0)) {
        alert("Each split payment amount must be greater than zero.");
        return null;
    }

    return methods.map((method, index) => ({
        method,
        amount: Number(amounts[index]).toFixed(2),
        reference_no: $(`#split-reference-${index + 1}`).val().trim(),
    }));
}

$("input[name='payment-type']").on("change", function () {
    const isSplit = this.value === "split";
    $("#split-payment-fields").toggleClass("hidden", !isSplit);
    updateSplitRemainder(isSplit);
});
$("#split-amount-1").on("input", () => updateSplitRemainder(false));
$("#split-method-1, #split-method-2").on("change", function () {
    syncSplitMethodOptions(this.id);
});
syncSplitMethodOptions();

function collectBuyerDataIfNeeded(amount) {
    if (Number(amount) <= Number(buyerPanThreshold || 10000)) {
        return {};
    }

    const buyerName = prompt("Buyer name is required for invoices above NPR 10,000.");
    if (!buyerName) {
        return null;
    }

    const buyerPan = prompt("Buyer PAN is required for invoices above NPR 10,000.");
    if (!buyerPan) {
        return null;
    }

    const buyerAddress = prompt("Buyer address (optional).") || "";

    return {
        buyer_name: buyerName,
        buyer_pan: buyerPan,
        buyer_address: buyerAddress,
    };
}

function collectCreditDataIfNeeded(paymentMethod) {
    if (paymentMethod !== "credit") {
        return {};
    }

    const creditCustomerName = prompt("Credit customer name is required.");
    if (!creditCustomerName) {
        return null;
    }

    const creditCustomerContact = prompt("Credit customer contact (optional).") || "";

    return {
        credit_customer_name: creditCustomerName,
        credit_customer_contact: creditCustomerContact,
    };
}

function billingErrorMessage(error, fallback) {
    if (error.responseJSON && error.responseJSON.message) {
        return error.responseJSON.message;
    }

    if (error.responseJSON && error.responseJSON.errors) {
        return Object.values(error.responseJSON.errors).flat().join("\n");
    }

    return fallback;
}

// delete item from order
function delItem(menuId) {
    const item = orderItems.find((item) => item.id === menuId);
    if (item) {
        orderItems.splice(orderItems.indexOf(item), 1);
        renderOrderTable();
    }
}
function toggleOrderType(to) {
    console.log("Toggling order type to: " + to);

    // Cache jQuery selectors to avoid repeated DOM queries
    const $takeaway = $("#takeaway");
    const $dineIn = $("#dine_in");
    const $kotOrder = $("#kot-order");
    const $table = $("#table");

    // Define the classes for active and inactive states
    const activeClasses = "active bg-green-700 font-bold";
    const inactiveClasses = "bg-gray-600 hover:bg-gray-700";

    const isTakeaway = to === "takeaway";

    // Set styles for the active and inactive buttons
    $takeaway
        .toggleClass(activeClasses, isTakeaway)
        .toggleClass(inactiveClasses, !isTakeaway);
    $dineIn
        .toggleClass(activeClasses, !isTakeaway)
        .toggleClass(inactiveClasses, isTakeaway);

    // Toggle visibility of other elements based on the order type
    $kotOrder.toggle(!isTakeaway); // Show for dine-in, hide for takeaway
    $table.toggleClass("hidden", isTakeaway); // Add 'hidden' class for takeaway
}
// Order Table Functions End

//------------------------------------------------------------------------------------------------------------------------------

// Order Table Helper Functions Start

// scroll to top of the order table
function scrollToTop() {
    const lastChild = $("#order-items-body")[0].lastElementChild;

    lastChild.scrollIntoView({
        behavior: "smooth",
        block: "start",
    });
}

// play audio
function playAudio() {
    var audio = new Audio(audioUrl);
    audio.volume = 0.1;
    var playback = audio.play();

    if (playback && typeof playback.catch === "function") {
        playback.catch(function () {
            // Browsers may block audio until the user has interacted with the page.
        });
    }
}
// Order Table Helper Functions End

//------------------------------------------------------------------------------------------------------------------------------

// DOM Ready Functions Start
$(document).ready(function () {
    // Show the first category by default
    $(".category button:first-child").click();

    // Filter all menu names
    filterAllMenuNames();

    // Add event listener to Shortcode input
    $("#shortcode-input").keypress(function (event) {
        if (event.which === 13) {
            event.preventDefault();
            searchByShortcode();
        }
    });

    // on load focus on shortcode input
    $("#shortcode-input").focus();

    // Add event listener to Customer Data input
    $("#customerName, #mobileNumber").on("keyup", function (event) {
        // Check if Enter key is pressed (key code 13)
        if (event.which === 13) {
            // Trigger the click event on the save button
            $("#saveCustomerDataBtn").click();
        }
    });

    // Add event listener to Search input with debounce
    var timer = null;
    $("#search-input").keyup(function () {
        clearTimeout(timer);
        timer = setTimeout(searchByName, 500);
    });

    // set default payment type
    $("#cash").prop("checked", true);
    calculateTotal();

    // Hide KOT if Takeaway is selected
    if ($("#takeaway").hasClass("active")) {
        $("#kot-order").hide();
        $("#table").addClass("hidden");
    }

    //check if any previous KOTs exist
    hasPrevOrders = existingTableOrderTotal > 0;

    // add click event listener to order type options
    $("#order-type-options div").click(function () {
        const orderType = $(this).attr("id");
        toggleOrderType(orderType);
    });
});

// DOM Ready Functions End

//------------------------------------------------------------------------------------------------------------------------------

// Search Functions Start

function searchByShortcode() {
    var shortcodeInput = $("#shortcode-input").val().toLowerCase();
    const menuId = menuShortCuts[shortcodeInput];
    if (menuId) {
        addItemToOrder(menuId);
    } else {
        alert("Invalid Shortcode");
    }
    $("#shortcode-input").val("");
    $("#shortcode-input").focus();
}

// Search by name
function searchByName() {
    const searchInput = $("#search-input").val().toLowerCase().trim();
    if (searchInput === "") {
        $(".menu-items button").show();
        return;
    }

    $(".menu-items button").each(function () {
        const menuItem = $(this);
        const menuItemName = menuItem.text().toLowerCase();

        if (
            menuItemName.startsWith(searchInput) ||
            menuItemName.includes(searchInput)
        ) {
            var menudivId = menuItem.parent().attr("id");
            showMenu(menudivId);
            menuItem.show();
        } else {
            menuItem.hide();
        }
    });
}

// Search Functions End

//------------------------------------------------------------------------------------------------------------------------------

// Order Functions Start

// Cancel Order
$("#cancel-order").click(function () {
    orderItems.splice(0, orderItems.length);
    renderOrderTable();
    $("input[type='radio']").prop("checked", false);
    $("#cash").prop("checked", true);
    $("#split-payment-fields").addClass("hidden");
    $("#split-amount-1, #split-amount-2, #split-reference-1, #split-reference-2").val("");
    $("textarea").val("");
    $("input[type='checkbox']").prop("checked", false);
});

// Order Functions End

//------------------------------------------------------------------------------------------------------------------------------

// Modal Functions Start

// Notes Modal Start

// Add Notes Modal Open
document.getElementById("add-notes-btn").addEventListener("click", function () {
    document.getElementById("addNotesModal").style.display = "flex";
});

// Add Notes Modal Close
document
    .querySelectorAll('[data-close="addNotesModal"]')
    .forEach(function (element) {
        element.addEventListener("click", function () {
            document.getElementById("addNotesModal").style.display = "none";
        });
    });

// Notes Modal Save Button
document.getElementById("saveNotesBtn").addEventListener("click", function () {
    selectedNotes.length = 0;
    $('input[name="notes"]:checked').each(function () {
        selectedNotes.push($(this).next().text());
    });

    const customNotesValue = $("#customNotes").val().trim();
    if (customNotesValue !== "") {
        const customNotesArray = customNotesValue.split(",");
        selectedNotes.push(...customNotesArray);
    }

    document.getElementById("addNotesModal").style.display = "none";
});

// Notes Modal End

// Modal Functions End

//-----------------------------------------------------------------------------------------------------------------------------

//------------------------------------------------------------------------------------------------------------------------------

// Save Order Functions Start

// Bill Order function
$("#bill-order").click(function (e) {
    e.preventDefault(); // Prevent default action

    if (orderItems.length === 0) {
        if (hasPrevOrders) {
            billTable();
        } else {
            alert("No Items Selected");
        }
        return;
    }

    let printBill = true;
    let hasNewOrders = orderItems.length > 0;
    let isTableToBePaid = $("#settle-order").length > 0;

    if (hasNewOrders) {
        saveOrder(printBill, false);
    } else if (isTableToBePaid) {
        printDuplicateBill();
    } else {
        billTable();
    }
});

$("#kot-order").click(function () {
    if (orderItems.length === 0) {
        alert("No Items Selected");
        return;
    }
    saveOrder();
});

// Save order
function saveOrder(printBill = false) {
    //validate order
    if (!hasPrevOrders && orderItems.length === 0) {
        alert("No Items Selected");
        return;
    }
    var tableId = null;
    var isPickUpOrder = false;
    const billTable = printBill;

    if ($("#takeaway").hasClass("active")) {
        tableId = null;
        isPickUpOrder = true;
    } else {
        tableId = $("#table").data("tableid");
    }

    const paymentMethod = printBill ? $("input[name='payment-type']:checked").val() : "cash";

    const order = {
        orderItems: orderItems,
        total: $("#total").text(),
        discount: $("#discount").text(),
        grandtotal: $("#grandtotal").text(),
    };

    const buyerData = printBill ? collectBuyerDataIfNeeded($("#billing-total").text()) : {};
    if (buyerData === null) {
        return;
    }

    const creditData = printBill ? collectCreditDataIfNeeded(paymentMethod) : {};
    if (creditData === null) {
        return;
    }

    const payments = printBill ? collectSplitPaymentsIfNeeded(paymentMethod) : [];
    if (payments === null) {
        return;
    }

    var csrf_token = $('meta[name="csrf-token"]').attr("content");
    showLoader();
    $.ajax({
        url: orderSubmitUrl,
        type: "POST",
        data: {
            source: SOURCE,
            tableId: tableId,
            specialInstructions: selectedNotes,
            isPickUpOrder: isPickUpOrder,
            paymentMethod: paymentMethod,
            payments,
            print_copies: $("#print-copies").val() || defaultPrintCopies,
            billTable: billTable,
            order: order,
            ...buyerData,
            ...creditData,
        },
        headers: {
            "X-CSRF-TOKEN": csrf_token,
        },
        contentType: "application/x-www-form-urlencoded",
        success: function (response) {
            if (response.status === "success") {
                $("#cancel-order").click();
                window.location.replace(indexUrl);
            } else {
                alert("Order Save Failed");
            }
        },
        error: function (error) {
            console.log(error);
            alert(billingErrorMessage(error, "Order Save Failed"));
        },
        complete: function () {
            hideLoader();
        },
    });
}

function billTable() {
    let tableId = $("#table").data("tableid");
    let csrf_token = $('meta[name="csrf-token"]').attr("content");
    let paymentType = $("input[name='payment-type']:checked").val();
    const buyerData = collectBuyerDataIfNeeded($("#billing-total").text());
    if (buyerData === null) {
        return;
    }
    let creditData = collectCreditDataIfNeeded(paymentType);

    if (creditData === null) {
        return;
    }

    const payments = collectSplitPaymentsIfNeeded(paymentType);
    if (payments === null) {
        return;
    }

    showLoader();

    $.ajax({
        url: billTableUrl,
        type: "POST",
        data: {
            tableId: tableId,
            paymentType: paymentType,
            payments,
            print_copies: $("#print-copies").val() || defaultPrintCopies,
            ...buyerData,
            ...creditData,
        },
        headers: {
            "X-CSRF-TOKEN": csrf_token,
        },
        contentType: "application/x-www-form-urlencoded",
        success: function (response) {
            if (response.status === "success") {
                $("#cancel-order").click();
                window.location.replace(indexUrl);
            } else {
                alert("Table Billing Failed");
            }
        },
        error: function (error) {
            console.log(error);
            alert(billingErrorMessage(error, "Table Billing Failed"));
        },
        complete: function () {
            hideLoader();
        },
    });
}

// close table
$("#settle-order").click(function () {
    var tableId = $("#table").data("tableid");
    var csrf_token = $('meta[name="csrf-token"]').attr("content");
    showLoader();
    $.ajax({
        url: settleTableUrl,
        type: "POST",
        data: {
            tableId: tableId,
            paymentType: $("input[name='payment-type']:checked").val(),
        },
        headers: {
            "X-CSRF-TOKEN": csrf_token,
        },
        contentType: "application/x-www-form-urlencoded",
        success: function (response) {
            if (response.status === "success") {
                $("#cancel-order").click();
                window.location.replace(indexUrl);
            } else {
                alert("Table Settlement Failed");
            }
        },
        error: function (error) {
            console.log(error);
            alert(billingErrorMessage(error, "Table Settlement Failed"));
        },
        complete: function () {
            hideLoader();
        },
    });
});

// Save Order Functions End

// Print Duplicate Bill
function printDuplicateBill() {
    var tableId = $("#table").data("tableid");
    var csrf_token = $('meta[name="csrf-token"]').attr("content");
    showLoader();
    $.ajax({
        url: billTableUrl,
        type: "POST",
        data: {
            tableId: tableId,
            printDuplicateBill: true,
        },
        headers: {
            "X-CSRF-TOKEN": csrf_token,
        },
        contentType: "application/x-www-form-urlencoded",
        success: function (response) {
            if (response.status === "success") {
                $("#cancel-order").click();
                window.location.replace(indexUrl);
            } else {
                alert("Bill Print Failed");
            }
        },
        error: function (error) {
            console.log(error);
            alert("Bill Print Failed");
        },
        complete: function () {
            hideLoader();
        },
    });
}
//------------------------------------------------------------------------------------------------------------------------------

// function to add <br> to menu names where there is a space after every two words
// This is done to make sure that the menu names are displayed correctly in the POS
function filterAllMenuNames() {
    $(".menu-items button [data-menu-name]").each(function () {
        var menuName = $(this).text();
        var filteredMenuName = menuName.replace(/(\w+)\s(\w+)\s/g, "$1 $2<br>");
        $(this).html(filteredMenuName);
    });
}
