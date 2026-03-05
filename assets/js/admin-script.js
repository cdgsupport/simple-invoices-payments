jQuery(document).ready(function ($) {
  // ========================================
  // Late Fee Rules Management
  // ========================================
  function initializeRule(rule) {
    const feeType = rule.find(".fee-type-select").val();
    const maxAmountRow = rule.find(".max-amount-row");
    maxAmountRow.toggle(feeType === "progressive");

    // Update amount label and description based on fee type
    const amountLabel = rule.find(".amount-label");
    const amountDescription = rule.find(".amount-description");
    const amountType = rule.find(".amount-type");

    switch (feeType) {
      case "flat":
        amountLabel.text("Amount");
        amountType.text("$");
        amountDescription.text("Fixed amount to charge");
        break;
      case "percentage":
        amountLabel.text("Percentage");
        amountType.text("%");
        amountDescription.text("Percentage of original invoice amount");
        break;
      case "progressive":
        amountLabel.text("Monthly Amount");
        amountType.text("$");
        amountDescription.text("Amount to add each month");
        break;
    }
  }

  // Initialize fee type descriptions
  $(document).on("change", ".fee-type-select", function () {
    const rule = $(this).closest(".late-fee-rule");
    initializeRule(rule);

    const selectedType = $(this).val();
    rule.find(".fee-type-description p").hide();
    rule.find("." + selectedType + "-desc").show();
  });

  $(document).on("click", ".add-rule", function () {
    const ruleCount = $(".late-fee-rule").length;
    const firstRule = $(".late-fee-rule").first();
    const newRule = firstRule.clone();

    newRule.find("h4").text("Rule #" + (ruleCount + 1));
    newRule.find("input").val("");
    newRule.find("select").prop("selectedIndex", 0);

    newRule.find("input, select").each(function () {
      const name = $(this).attr("name");
      if (name) {
        $(this).attr("name", name.replace(/\[\d+\]/, "[" + ruleCount + "]"));
      }
    });

    $("#late-fee-rules").append(newRule);
    initializeRule(newRule);
  });

  // Remove rule
  $(document).on("click", ".remove-rule", function () {
    if ($(".late-fee-rule").length > 1) {
      $(this).closest(".late-fee-rule").remove();
      // Update rule numbers
      $(".late-fee-rule").each(function (index) {
        $(this)
          .find("h4")
          .text("Rule #" + (index + 1));
        $(this)
          .find("input, select")
          .each(function () {
            const name = $(this).attr("name");
            if (name) {
              $(this).attr("name", name.replace(/\[\d+\]/, "[" + index + "]"));
            }
          });
      });
    } else {
      alert("You must keep at least one rule.");
    }
  });

  // Initialize existing rules on page load
  $(".late-fee-rule").each(function () {
    initializeRule($(this));
    const selectedType = $(this).find(".fee-type-select").val();
    if (selectedType) {
      $(this)
        .find("." + selectedType + "-desc")
        .show();
    }
  });

  // ========================================
  // Invoice Management
  // ========================================
  $(document).on("click", ".edit-invoice", function () {
    const invoiceId = $(this).data("id");
    const nonce = $(this).data("nonce");

    $.ajax({
      url: cisAdminData.ajaxUrl,
      type: "POST",
      data: {
        action: "get_invoice",
        invoice_id: invoiceId,
        nonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          showEditModal(response.data);
        } else {
          alert("Failed to fetch invoice details: " + response.data);
        }
      },
      error: function (xhr, status, error) {
        console.error("AJAX Error:", error);
        alert("Server error occurred.");
      },
    });
  });

  $(document).on("click", ".delete-invoice", function () {
    if (
      confirm(
        cisAdminData.strings.confirmDelete ||
          "Are you sure you want to delete this invoice? This action cannot be undone."
      )
    ) {
      const invoiceId = $(this).data("id");
      const nonce = $(this).data("nonce");
      $.ajax({
        url: cisAdminData.ajaxUrl,
        type: "POST",
        data: {
          action: "delete_invoice",
          invoice_id: invoiceId,
          nonce: nonce,
        },
        success: function (response) {
          if (response.success) {
            location.reload();
          } else {
            alert("Failed to delete invoice.");
          }
        },
        error: function () {
          alert("Server error occurred.");
        },
      });
    }
  });

  // Handle frequency toggle in create form
  $("#create-invoice-frequency").on("change", function () {
    const isRecurring = $(this).val() === "recurring";
    $(".create-recurring-settings").toggle(isRecurring);

    if (!isRecurring) {
      $("#recurring_interval").val("0");
      $("#recurring_unit").val("");
    }
  });

  // Initialize frequency display on page load
  $("#create-invoice-frequency").trigger("change");

  function showEditModal(invoice) {
    let modal = $("#edit-invoice-modal");
    if (!modal.length) {
      modal = $(`
                <div id="edit-invoice-modal" class="modal">
                    <div class="modal-content">
                        <span class="close">&times;</span>
                        <h3>Edit Invoice</h3>
                        <form id="edit-invoice-form">
                            <input type="hidden" name="invoice_id" value="${invoice.id}">
                            <table class="form-table">
                                <tr>
                                    <th>Amount ($)</th>
                                    <td><input type="number" name="amount" step="0.01" required></td>
                                </tr>
                                <tr>
                                    <th>Description</th>
                                    <td><textarea name="description" rows="3" style="width: 100%;"></textarea></td>
                                </tr>
                                <tr>
                                    <th>Due Date</th>
                                    <td><input type="date" name="due_date" required></td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td>
                                        <select name="status">
                                            <option value="pending">Pending</option>
                                            <option value="paid">Paid</option>
                                            <option value="cancelled">Cancelled</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Frequency</th>
                                    <td>
                                        <select name="frequency" id="invoice-frequency">
                                            <option value="one-time">One-Time Payment</option>
                                            <option value="recurring">Recurring</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr class="recurring-settings" style="display: none;">
                                    <th>Recurring Settings</th>
                                    <td>
                                        <input type="number" name="recurring_interval" min="0" style="width: 60px;">
                                        <select name="recurring_unit">
                                            <option value="">Select Period</option>
                                            <option value="day">Day(s)</option>
                                            <option value="week">Week(s)</option>
                                            <option value="month">Month(s)</option>
                                            <option value="year">Year(s)</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr class="recurring-settings" style="display: none;">
                                    <th>Next Recurring Date</th>
                                    <td>
                                        <input type="date" name="next_recurring_date">
                                        <p class="description">Next date when the recurring invoice will be generated</p>
                                    </td>
                                </tr>
                            </table>
                            <button type="submit" class="button button-primary">Update Invoice</button>
                        </form>
                    </div>
                </div>
            `).appendTo("body");

      // Handle frequency change
      modal.find("#invoice-frequency").on("change", function () {
        const isRecurring = $(this).val() === "recurring";
        modal.find(".recurring-settings").toggle(isRecurring);

        // Clear recurring fields if switching to one-time
        if (!isRecurring) {
          modal.find('[name="recurring_interval"]').val("");
          modal.find('[name="recurring_unit"]').val("");
          modal.find('[name="next_recurring_date"]').val("");
        }
      });

      // Set up modal close handler
      modal.find(".close").on("click", function () {
        modal.hide();
      });

      // Close modal when clicking outside
      $(window).on("click", function (event) {
        if ($(event.target).is(modal)) {
          modal.hide();
        }
      });
    }

    // Populate form fields
    modal.find('[name="amount"]').val(invoice.amount);
    modal.find('[name="description"]').val(invoice.description);
    modal.find('[name="due_date"]').val(invoice.due_date);
    modal.find('[name="status"]').val(invoice.status);

    // Set frequency and show/hide recurring fields
    const isRecurring =
      invoice.recurring_interval > 0 && invoice.recurring_unit;
    modal
      .find("#invoice-frequency")
      .val(isRecurring ? "recurring" : "one-time")
      .trigger("change");

    if (isRecurring) {
      modal.find('[name="recurring_interval"]').val(invoice.recurring_interval);
      modal.find('[name="recurring_unit"]').val(invoice.recurring_unit);
      modal
        .find('[name="next_recurring_date"]')
        .val(invoice.next_recurring_date || "");
    }

    // Show modal
    modal.show();

    // Handle form submission
    $("#edit-invoice-form")
      .off("submit")
      .on("submit", function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append("action", "update_invoice");
        formData.append("nonce", $("#invoice_nonce").val());

        // Clear recurring fields if one-time payment is selected
        if (formData.get("frequency") === "one-time") {
          formData.set("recurring_interval", "0");
          formData.set("recurring_unit", "");
          formData.set("next_recurring_date", "");
        }

        $.ajax({
          url: cisAdminData.ajaxUrl,
          type: "POST",
          data: formData,
          processData: false,
          contentType: false,
          success: function (response) {
            if (response.success) {
              location.reload();
            } else {
              alert("Failed to update invoice.");
            }
          },
          error: function () {
            alert("Server error occurred.");
          },
        });
      });
  }

  // ========================================
  // Live Search Functionality
  // ========================================
  let searchTimer;
  $("#invoice-search").on("keyup", function () {
    clearTimeout(searchTimer);
    const searchTerm = $(this).val().toLowerCase();

    searchTimer = setTimeout(function () {
      if (searchTerm.length > 2 || searchTerm.length === 0) {
        filterInvoiceTable(searchTerm);
      }
    }, 300);
  });

  function filterInvoiceTable(searchTerm) {
    const $rows = $(".invoices-table tbody tr");

    if (searchTerm === "") {
      $rows.show();
      return;
    }

    $rows.each(function () {
      const $row = $(this);
      const text = $row.text().toLowerCase();

      if (text.indexOf(searchTerm) !== -1) {
        $row.show();
      } else {
        $row.hide();
      }
    });

    // Show message if no results
    const visibleRows = $rows.filter(":visible").length;
    let $noResults = $("#no-search-results");

    if (visibleRows === 0 && !$noResults.length) {
      $(".invoices-table tbody").append(
        '<tr id="no-search-results"><td colspan="9" style="text-align: center; padding: 20px;">No invoices found matching your search.</td></tr>'
      );
    } else if (visibleRows > 0 && $noResults.length) {
      $noResults.remove();
    }
  }

  // ========================================
  // Table Sorting Functionality (Production)
  // ========================================
  let currentSortColumn = "due_date";
  let currentSortOrder = "asc";

  // Initialize table sorting
  function initTableSorting() {
    const $table = $("#invoices-table");
    if (!$table.length) return;

    // Remove any existing click handlers to avoid duplicates
    $table.find("th.sortable").off("click.sorting");

    // Add click handlers to sortable headers
    $table.find("th.sortable").on("click.sorting", function () {
      const $th = $(this);
      const column = $th.data("column");
      const dataType = $th.data("type") || "string";

      // Determine sort order
      let order = "asc";
      if (currentSortColumn === column && currentSortOrder === "asc") {
        order = "desc";
      }

      // Update visual indicators
      $table.find("th.sortable").removeClass("sort-asc sort-desc");

      $th.addClass("sort-" + order);

      // Sort the table
      sortTable(column, order, dataType);

      // Update current sort state
      currentSortColumn = column;
      currentSortOrder = order;
    });
  }

  function sortTable(column, order, dataType) {
    const $tbody = $("#invoices-table tbody");
    const $rows = $tbody.find("tr").not("#no-search-results");

    if ($rows.length === 0) return;

    // Convert to array for sorting
    const rowsArray = $rows.toArray();

    rowsArray.sort(function (a, b) {
      const $a = $(a);
      const $b = $(b);

      let aVal, bVal;

      // Find the correct column index
      const columnIndex = getColumnIndex(column);
      const $aTd = $a.find("td").eq(columnIndex);
      const $bTd = $b.find("td").eq(columnIndex);

      // Get sort values based on data type
      if (dataType === "numeric" || column === "amount") {
        aVal =
          parseFloat(
            $aTd.data("sort") || $aTd.text().replace(/[^0-9.-]/g, "")
          ) || 0;
        bVal =
          parseFloat(
            $bTd.data("sort") || $bTd.text().replace(/[^0-9.-]/g, "")
          ) || 0;
      } else if (dataType === "date") {
        aVal = new Date($aTd.data("sort") || $aTd.text() || "1970-01-01");
        bVal = new Date($bTd.data("sort") || $bTd.text() || "1970-01-01");
      } else {
        aVal = ($aTd.data("sort") || $aTd.text() || "")
          .toString()
          .toLowerCase();
        bVal = ($bTd.data("sort") || $bTd.text() || "")
          .toString()
          .toLowerCase();
      }

      let comparison = 0;
      if (aVal > bVal) {
        comparison = 1;
      } else if (aVal < bVal) {
        comparison = -1;
      }

      return order === "desc" ? comparison * -1 : comparison;
    });

    // Clear tbody and append sorted rows
    $tbody.empty();
    $.each(rowsArray, function (index, row) {
      $tbody.append(row);
    });

    // Re-append no-results message if it exists
    const $noResults = $("#no-search-results");
    if ($noResults.length) {
      $tbody.append($noResults);
    }
  }

  function getColumnIndex(column) {
    const columnMap = {
      invoice_number: 0,
      display_name: 1,
      amount: 2,
      due_date: 3,
      status: 4,
      created_at: 5,
      frequency: 6,
    };
    return columnMap[column] || 0;
  }

  // Initialize table sorting
  initTableSorting();

  // Set default sort indicator (Due Date ASC)
  setTimeout(function () {
    const $dueDateHeader = $('#invoices-table th[data-column="due_date"]');
    if ($dueDateHeader.length) {
      $dueDateHeader.addClass("sort-asc");
    }
  }, 100);

  // ========================================
  // Keyboard Shortcuts
  // ========================================
  $(document).on("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === "k") {
      e.preventDefault();
      $("#invoice-search").focus();
    }
  });

  // ========================================
  // Auto-save Draft for Create Invoice Form
  // ========================================
  let draftTimer;
  $(
    "#create-invoice-section input, #create-invoice-section select, #create-invoice-section textarea"
  ).on("input change", function () {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(saveDraft, 1000);
  });

  function saveDraft() {
    const formData = {
      user_id: $("#user_id").val(),
      amount: $("#amount").val(),
      description: $("#description").val(),
      due_date: $("#due_date").val(),
      frequency: $("#create-invoice-frequency").val(),
      recurring_interval: $("#recurring_interval").val(),
      recurring_unit: $("#recurring_unit").val(),
    };

    localStorage.setItem("invoice_draft", JSON.stringify(formData));
    showDraftSaved();
  }

  function showDraftSaved() {
    let $indicator = $("#draft-saved-indicator");
    if (!$indicator.length) {
      $indicator = $(
        '<span id="draft-saved-indicator" style="color: green; margin-left: 10px;">Draft saved</span>'
      );
      $('.create-invoice-title, h2:contains("Create New Invoice")')
        .first()
        .append($indicator);
    }

    $indicator.fadeIn(200);
    setTimeout(function () {
      $indicator.fadeOut(500);
    }, 2000);
  }

  // Load draft on page load
  const savedDraft = localStorage.getItem("invoice_draft");
  if (savedDraft) {
    try {
      const draft = JSON.parse(savedDraft);
      $("#user_id").val(draft.user_id);
      $("#amount").val(draft.amount);
      $("#description").val(draft.description);
      $("#due_date").val(draft.due_date);
      $("#create-invoice-frequency").val(draft.frequency).trigger("change");
      $("#recurring_interval").val(draft.recurring_interval);
      $("#recurring_unit").val(draft.recurring_unit);
    } catch (e) {
      console.error("Error loading draft:", e);
      localStorage.removeItem("invoice_draft");
    }
  }

  // Clear draft on successful submission
  $("form").on("submit", function () {
    if ($(this).find('[name="create_invoice"]').length) {
      localStorage.removeItem("invoice_draft");
    }
  });

  // ========================================
  // Enhanced Modal Management
  // ========================================

  // ========================================
  // Status Tooltip
  // ========================================
  $(document).on("mouseenter", ".has-tooltip", function (e) {
    var text = $(this).attr("data-tooltip");
    if (!text) return;
    var $tip = $('<div class="cis-tooltip"></div>').text(text).appendTo("body");
    var rect = this.getBoundingClientRect();
    $tip.css({
      top: rect.top - $tip.outerHeight() - 6,
      left: rect.left + rect.width / 2 - $tip.outerWidth() / 2,
    });
  });

  $(document).on("mouseleave", ".has-tooltip", function () {
    $(".cis-tooltip").remove();
  });

  // Close modals with Escape key
  $(document).on("keydown", function (e) {
    if (e.key === "Escape") {
      $(".modal:visible").hide();
    }
  });

  // Prevent modal close when clicking inside modal content
  $(document).on("click", ".modal-content", function (e) {
    e.stopPropagation();
  });

  // ========================================
  // Enhanced Error Handling and User Feedback
  // ========================================

  // Show loading states for AJAX operations
  function showLoading($element, originalText) {
    $element.prop("disabled", true);
    $element.data("original-text", originalText || $element.text());
    $element.text("Loading...");
  }

  function hideLoading($element) {
    $element.prop("disabled", false);
    $element.text($element.data("original-text") || $element.text());
  }

  // Enhanced error display
  function showError(message, duration = 5000) {
    const $error = $(
      '<div class="notice notice-error is-dismissible"><p>' +
        message +
        "</p></div>"
    );
    $(".wrap h1").after($error);

    setTimeout(function () {
      $error.fadeOut(function () {
        $(this).remove();
      });
    }, duration);
  }

  function showSuccess(message, duration = 3000) {
    const $success = $(
      '<div class="notice notice-success is-dismissible"><p>' +
        message +
        "</p></div>"
    );
    $(".wrap h1").after($success);

    setTimeout(function () {
      $success.fadeOut(function () {
        $(this).remove();
      });
    }, duration);
  }

  // ========================================
  // Accessibility Enhancements
  // ========================================

  // Add ARIA labels for sort indicators
  function updateSortAria() {
    $("#invoices-table th.sortable").each(function () {
      const $th = $(this);
      const isActive = $th.hasClass("sort-asc") || $th.hasClass("sort-desc");
      const direction = $th.hasClass("sort-asc") ? "ascending" : "descending";

      if (isActive) {
        $th.attr("aria-sort", direction);
      } else {
        $th.attr("aria-sort", "none");
      }
    });
  }

  // Call after sorting
  $(document).on("click", "#invoices-table th.sortable", function () {
    setTimeout(updateSortAria, 100);
  });

  // Initialize ARIA attributes
  updateSortAria();
});
