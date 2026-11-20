(function () {
  "use strict";
  const K = window.KCBSA;
  const renderers = {};
  const official = ["Secretary", "Treasurer", "Welfare Officer", "Safety Officer", "Chairperson"];

  function appendMany(parent, ...nodes) {
    nodes.filter(Boolean).forEach((node) => parent.append(node));
    return parent;
  }

  function actions(...nodes) {
    const box = document.createElement("div");
    box.className = "button-row";
    return appendMany(box, ...nodes);
  }

  function sectionHeader(title, subtitle, right) {
    return K.pageHeader(title, subtitle, right ? actions(right) : null);
  }

  function selectOptions(rows, valueKey, labelKey, firstLabel = "Choose…") {
    return [{ value: "", label: firstLabel }, ...rows.map((row) => ({
      value: String(row[valueKey]),
      label: String(row[labelKey]),
    }))];
  }

  function daysLabel(value) {
    const days = Number(value);
    if (days < 0) return `${Math.abs(days)} days overdue`;
    if (days === 0) return "Expires today";
    return `${days} days left`;
  }

  function metricGrid(items) {
    const grid = document.createElement("div");
    grid.className = "grid grid-4";
    items.forEach((item) => grid.append(K.metric(item.label, item.value, item.note)));
    return grid;
  }

  function openTable(rows, allowedSuspend, arrearsLimit) {
    return K.table(
      ["Rider", "Phone", "Status", "Dues paid", "Arrears", "Plates", "Action"],
      rows.map((row) => {
        const action = actions(
          ["Secretary", "Chairperson"].includes(window.KCBSA_USER?.role)
            ? K.link("View", `rider_view.html?id=${encodeURIComponent(row.rider_id)}`, "button button-small")
            : null,
          allowedSuspend && row.status === "Active" && Number(row.arrears) >= arrearsLimit
            ? K.button("Suspend", async () => {
                try {
                  await K.api("riders.php?action=suspend", { method: "POST", body: JSON.stringify({ rider_id: row.rider_id }) });
                  K.toast(`${row.full_name} has been suspended and the change recorded.`);
                  await renderers.riders(window.KCBSA_USER, document.querySelector(".content"));
                } catch (error) {
                  K.toast(error.message, true);
                }
              }, "button button-small button-danger")
            : null,
        );
        return [
          row.full_name,
          row.phone,
          K.badge(row.status),
          K.formatMoney(row.paid_total),
          K.formatMoney(row.arrears),
          row.plates || "—",
          action,
        ];
      }),
      "No riders match the search.",
    );
  }

  async function getRiders() {
    return K.api("riders.php?action=list");
  }

  async function fillRiderSelect(select, selected) {
    const riders = await getRiders();
    select.replaceChildren(...selectOptions(riders, "rider_id", "full_name").map((item) => {
      const option = K.text("option", item.label);
      option.value = item.value;
      if (item.value === String(selected || "")) option.selected = true;
      return option;
    }));
  }

  function setFormFieldWide(node) {
    node.classList.add("wide");
    return node;
  }

  async function loginPage() {
    const form = document.getElementById("login-form");
    const notice = document.getElementById("notice");
    if (!form) return;
    try {
      const auth = await K.api("auth.php?action=me");
      K.setCsrf(auth.csrf_token);
      if (auth.user) {
        window.location.replace(auth.user.role === "Rider" ? "my_account.html" : "dashboard.html");
        return;
      }
    } catch (_) {
      // The login form remains usable if a current session is absent.
    }
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      notice.className = "notice";
      const submit = form.querySelector("button[type=submit]");
      submit.disabled = true;
      try {
        const data = Object.fromEntries(new FormData(form).entries());
        const result = await K.api("auth.php?action=login", { method: "POST", body: JSON.stringify(data) });
        K.setCsrf(result.csrf_token);
        window.location.replace(result.redirect);
      } catch (error) {
        notice.textContent = error.message;
        notice.className = "notice error is-visible";
      } finally {
        submit.disabled = false;
      }
    });
  }

  renderers.dashboard = async (user, root) => {
    if (user.role === "Rider") return;
    const data = await K.api("dashboard.php");
    window.KCBSA_USER = user;
    root.append(sectionHeader("Stage overview", "Operational signals for Kisenyi Central Boda-Boda Stage Association."));
    const s = data.summary;
    root.append(metricGrid([
      { label: "Active riders", value: s.active_riders, note: `${s.total_riders} registered · ${s.suspended_riders} suspended` },
      { label: "Collections this month", value: K.formatMoney(s.monthly_collections), note: "Net dues recorded from the first of this month" },
      { label: "Collections this week", value: K.formatMoney(s.weekly_collections), note: "Net dues recorded in the last 7 days" },
      { label: "Dues arrears", value: K.formatMoney(s.arrears_total), note: "Outstanding balance across active riders" },
      { label: "Claims paid", value: K.formatMoney(s.claims_paid_total), note: "Total approved amounts already paid" },
      { label: "Welfare fund", value: K.formatMoney(s.welfare_fund_balance), note: `${s.claims_awaiting_action} claims need attention` },
    ]));
    const trend = document.createElement("div");
    trend.className = "grid grid-2";
    const months = data.monthly_collections || [];
    trend.append(K.chart("Monthly dues collections", months.map((row) => row.label.slice(0, 3)), months.map((row) => row.collected), "bar"));
    trend.append(K.chart("New rider registrations", (data.membership_growth || []).map((row) => row.label.slice(0, 3)), (data.membership_growth || []).map((row) => row.joined), "line"));
    trend.append(K.chart("Welfare claims paid", (data.monthly_claims_paid || []).map((row) => row.label.slice(0, 3)), (data.monthly_claims_paid || []).map((row) => row.paid), "bar"));
    root.append(trend);
    const panels = document.createElement("div");
    panels.className = "grid grid-2";
    const arrearsPanel = K.card("Arrears list", "Highest arrears first. Review the riders at the top.");
    const settings = await K.api("settings.php");
    const limit = Number(settings.find((row) => row.setting_key === "arrears_limit")?.setting_value || 20000);
    arrearsPanel.append(openTable(data.arrears_list, ["Secretary", "Chairperson"].includes(user.role), limit));
    panels.append(arrearsPanel);
    const decisions = K.card("Decisions needed", "Claims awaiting a decision or payout.");
    const list = document.createElement("div");
    list.className = "notice-list";
    (data.claims_list || []).forEach((claim) => {
      const row = document.createElement("div");
      row.className = "notice-item";
      const copy = document.createElement("div");
      copy.append(K.text("strong", `${claim.full_name} · ${claim.claim_type}`), K.text("small", `${claim.status} · ${K.formatMoney(claim.amount_requested)} · ${K.formatDate(claim.date_submitted)}`));
      row.append(copy, K.badge(claim.status));
      list.append(row);
    });
    if (!data.claims_list.length) list.append(K.text("div", "No welfare claim decisions are waiting.", "empty"));
    decisions.append(list);
    panels.append(decisions);
    root.append(panels);
    const docs = K.card("Permit and insurance alerts", `${s.documents_alert} document(s) expired or due within the alert period.`);
    const alertList = document.createElement("div");
    alertList.className = "notice-list";
    (data.documents_alerts || []).forEach((item) => {
      const row = document.createElement("div");
      row.className = "notice-item";
      const copy = document.createElement("div");
      copy.append(K.text("strong", `${item.doc_type} · ${item.plate_number}`), K.text("small", `${item.full_name} · ${K.formatDate(item.expiry_date)}`));
      row.append(copy, K.badge(daysLabel(item.days_to_expiry)));
      alertList.append(row);
    });
    if (!data.documents_alerts.length) alertList.append(K.text("div", "No compliance alerts.", "empty"));
    docs.append(alertList);
    root.append(docs);
  };

  renderers.riders = async (user, root) => {
    window.KCBSA_USER = user;
    const registerLink = ["Secretary", "Chairperson"].includes(user.role)
      ? K.link("Register rider", "rider_form.html", "button button-primary") : null;
    const header = sectionHeader("Rider register", "Search stage membership and review dues status.", registerLink);
    if (["Secretary", "Chairperson"].includes(user.role)) {
      if (!header.querySelector(".button")) header.append(actions(registerLink));
    }
    root.append(header);
    const toolbar = document.createElement("div");
    toolbar.className = "toolbar";
    const search = K.field("Search riders", "q", "search", { placeholder: "Name, phone or national ID" });
    search.className = "search";
    toolbar.append(search);
    if (user.role === "Secretary") toolbar.append(K.link("Verified list", "export.html", "button"));
    root.append(toolbar);
    const area = K.card();
    root.append(area);
    const load = async () => {
      area.replaceChildren();
      const rows = await K.api(`riders.php?action=list&q=${encodeURIComponent(search.querySelector("input").value.trim())}`);
      const settings = await K.api("settings.php");
      const limit = Number(settings.find((row) => row.setting_key === "arrears_limit")?.setting_value || 20000);
      area.append(openTable(rows, ["Secretary", "Chairperson"].includes(user.role), limit));
    };
    search.querySelector("input").addEventListener("input", () => {
      window.clearTimeout(search.timer);
      search.timer = window.setTimeout(() => load().catch((error) => K.toast(error.message, true)), 220);
    });
    await load();
  };

  renderers.rider_form = async (user, root) => {
    root.append(sectionHeader("Register a rider", "New members receive an Active record; national ID must be unique."));
    const panel = K.card("Rider details", "Required fields are marked in the form.");
    const kin = K.field("Address", "address", "text", { required: true, maxLength: 180 });
    kin.classList.add("wide");
    const form = K.form([
      { label: "National ID", name: "national_id", options: { required: true, maxLength: 32 } },
      { label: "Full name", name: "full_name", options: { required: true, maxLength: 120 } },
      { label: "Phone", name: "phone", "type": "tel", options: { required: true, maxLength: 32 } },
      { label: "Date joined", name: "date_joined", type: "date", options: { required: true, value: new Date().toISOString().slice(0, 10) } },
      kin,
      { label: "Next of kin name", name: "next_of_kin_name", options: { maxLength: 120 } },
      { label: "Next of kin phone", name: "next_of_kin_phone", type: "tel", options: { maxLength: 32 } },
    ], "Save rider", async (data) => {
      const result = await K.api("riders.php?action=create", { method: "POST", body: JSON.stringify(data) });
      K.toast("Rider registered. Add one or more motorcycles on the rider record.");
      window.location.assign(`rider_view.html?id=${encodeURIComponent(result.rider_id)}`);
    });
    panel.append(form);
    root.append(panel);
  };

  renderers.rider_view = async (user, root) => {
    const id = new URLSearchParams(window.location.search).get("id");
    if (!id) throw new Error("A rider ID is required.");
    const [rider, bikes] = await Promise.all([
      K.api(`riders.php?action=get&id=${encodeURIComponent(id)}`),
      K.api(`motorcycles.php?action=list&rider_id=${encodeURIComponent(id)}`),
    ]);
    root.append(sectionHeader(rider.full_name, `Member #${rider.rider_id} · joined ${K.formatDate(rider.date_joined)}`));
    const grid = document.createElement("div");
    grid.className = "grid grid-3";
    grid.append(K.metric("Membership", rider.status), K.metric("Dues arrears", K.formatMoney(rider.arrears)), K.metric("Savings balance", K.formatMoney(rider.savings_balance)));
    root.append(grid);
    const detail = K.card("Rider details");
    const details = K.table(["National ID", "Phone", "Address", "Next of kin", "Next of kin phone"], [[
      rider.national_id, rider.phone, rider.address,
      rider.next_of_kin_name || "—", rider.next_of_kin_phone || "—",
    ]]);
    detail.append(details);
    if (["Secretary", "Chairperson"].includes(user.role)) {
      detail.append(K.form([
        { label: "Full name", name: "full_name", options: { required: true, maxLength: 120, value: rider.full_name } },
        { label: "Phone", name: "phone", type: "tel", options: { required: true, maxLength: 32, value: rider.phone } },
        { label: "Address", name: "address", options: { required: true, maxLength: 180, value: rider.address } },
        { label: "Next of kin name", name: "next_of_kin_name", options: { maxLength: 120, value: rider.next_of_kin_name || "" } },
        { label: "Next of kin phone", name: "next_of_kin_phone", type: "tel", options: { maxLength: 32, value: rider.next_of_kin_phone || "" } },
      ], "Save rider changes", async (data) => {
        await K.api("riders.php?action=update", { method: "POST", body: JSON.stringify({ ...data, rider_id: rider.rider_id }) });
        K.toast("Rider record updated.");
        window.location.reload();
      }));
    }
    root.append(detail);
    const bikePanel = K.card("Motorcycles", "Each plate number is unique across the stage.");
    bikePanel.append(K.table(["Plate", "Make / model", "Colour"], bikes.map((bike) => [bike.plate_number, bike.make_model, bike.colour || "—"])));
    if (["Secretary", "Chairperson"].includes(user.role)) {
      bikes.forEach((bike) => {
        const editForm = K.form([
          { label: "Plate number", name: "plate_number", options: { required: true, maxLength: 20, value: bike.plate_number } },
          { label: "Make / model", name: "make_model", options: { required: true, maxLength: 80, value: bike.make_model } },
          { label: "Colour", name: "colour", options: { maxLength: 40, value: bike.colour || "" } },
        ], `Update ${bike.plate_number}`, async (data) => {
          await K.api("motorcycles.php?action=update", { method: "POST", body: JSON.stringify({ ...data, rider_id: rider.rider_id, motorcycle_id: bike.motorcycle_id }) });
          K.toast("Motorcycle record updated.");
          window.location.reload();
        });
        bikePanel.append(editForm);
      });
      const bikeForm = K.form([
        { label: "Plate number", name: "plate_number", options: { required: true, maxLength: 20 } },
        { label: "Make / model", name: "make_model", options: { required: true, maxLength: 80 } },
        { label: "Colour", name: "colour", options: { maxLength: 40 } },
      ], "Add motorcycle", async (data) => {
        await K.api("motorcycles.php?action=create", { method: "POST", body: JSON.stringify({ ...data, rider_id: rider.rider_id }) });
        K.toast("Motorcycle added.");
        window.location.reload();
      });
      bikePanel.append(bikeForm);
    }
    root.append(bikePanel);
  };

  renderers.dues = async (user, root) => {
    const right = user.role === "Treasurer" ? K.link("Record payment", "#payment-form", "button button-primary") : null;
    root.append(sectionHeader("Dues and receipts", "Record dues, review the ledger and print a receipt.", right));
    if (user.role === "Treasurer") {
      const panel = K.card("Record dues payment", "A saved payment receives a receipt number you can print.");
      const riders = await getRiders();
      const riderSelect = K.field("Rider", "rider_id", "select", { required: true, options: selectOptions(riders, "rider_id", "full_name") });
      const paymentForm = K.form([
        riderSelect,
        { label: "Amount (UGX)", name: "amount", type: "number", options: { required: true, min: 1, step: 500 } },
        { label: "Payment date", name: "payment_date", type: "date", options: { required: true, value: new Date().toISOString().slice(0, 10) } },
        { label: "Period covered", name: "period_covered", options: { required: true, placeholder: "e.g. Week 48, 2026" } },
        { label: "Payment method", name: "method", type: "select", options: { required: true, options: [{ value: "", label: "Choose…" }, { value: "Cash", label: "Cash" }, { value: "Mobile Money", label: "Mobile Money" }] } },
      ], "Save payment", async (data) => {
        const result = await K.api("dues.php?action=pay", { method: "POST", body: JSON.stringify(data) });
        K.toast("Payment recorded.");
        window.location.assign(`receipt.html?id=${encodeURIComponent(result.payment_id)}`);
      });
      paymentForm.id = "payment-form";
      panel.append(paymentForm);
      root.append(panel);
    }
    const area = K.card("Payment ledger", "Payments are kept as permanent records. Corrections are recorded as reversals.");
    const rows = await K.api("dues.php?action=list");
    const tableRows = rows.map((item) => {
      const rowActions = actions(
        K.link("Receipt", `receipt.html?id=${encodeURIComponent(item.payment_id)}`, "button button-small"),
      );
      if (user.role === "Treasurer" && Number(item.amount) > 0 && !String(item.period_covered).startsWith("REVERSAL")) {
        rowActions.append(K.button("Reverse", async () => {
          const reason = window.prompt("Reason for reversing this payment:");
          if (!reason) return;
          try {
            await K.api("dues.php?action=reverse", { method: "POST", body: JSON.stringify({ payment_id: item.payment_id, reason }) });
            K.toast("Reversal recorded with an audit note.");
            window.location.reload();
          } catch (error) { K.toast(error.message, true); }
        }, "button button-small button-danger"));
      }
      return [item.payment_id, item.full_name, K.formatDate(item.payment_date), item.period_covered, item.method, K.formatMoney(item.amount), rowActions];
    });
    area.append(K.table(["Receipt #", "Rider", "Date", "Period", "Method", "Amount", "Action"], tableRows));
    root.append(area);
  };

  renderers.savings = async (user, root) => {
    root.append(sectionHeader("Savings statement", user.role === "Rider" ? "Your personal deposits, withdrawals and running balance." : "Record voluntary savings and review member statements."));
    if (user.role === "Treasurer") {
      const panel = K.card("Record savings transaction", "Withdrawals above the available balance are declined.");
      const riders = await getRiders();
      const form = K.form([
        { label: "Rider", name: "rider_id", type: "select", options: { required: true, options: selectOptions(riders, "rider_id", "full_name") } },
        { label: "Transaction", name: "trans_type", type: "select", options: { required: true, options: [{ value: "", label: "Choose…" }, { value: "Deposit", label: "Deposit" }, { value: "Withdrawal", label: "Withdrawal" }] } },
        { label: "Amount (UGX)", name: "amount", type: "number", options: { required: true, min: 1, step: 500 } },
      ], "Save transaction", async (data) => {
        const result = await K.api("savings.php?action=record", { method: "POST", body: JSON.stringify(data) });
        K.toast(`Transaction saved. New balance: ${K.formatMoney(result.balance)}`);
        window.location.reload();
      });
      panel.append(form);
      root.append(panel);
    }
    const panel = K.card("Statement", "Running balance is calculated chronologically.");
    const records = await K.api("savings.php?action=list");
    const chronological = [...records].sort((a, b) => new Date(a.trans_date) - new Date(b.trans_date) || Number(a.savings_id) - Number(b.savings_id));
    let balance = 0;
    const balances = new Map();
    chronological.forEach((row) => {
      balance += row.trans_type === "Deposit" ? Number(row.amount) : -Number(row.amount);
      balances.set(String(row.savings_id), balance);
    });
    const ordered = [...chronological].reverse();
    panel.append(K.metric("Current savings balance", K.formatMoney(balance)));
    panel.append(K.table(["Date", "Rider", "Transaction", "Amount", "Running balance"], ordered.map((row) => [
      K.formatDate(row.trans_date), row.full_name || user.username, row.trans_type,
      K.formatMoney(row.amount), K.formatMoney(balances.get(String(row.savings_id))),
    ]), "No savings transactions yet."));
    root.append(panel);
  };

  renderers.claims = async (user, root) => {
    const right = ["Secretary", "Treasurer", "Welfare Officer", "Chairperson"].includes(user.role)
      ? K.link("Submit claim", "claim_form.html", "button button-primary") : null;
    root.append(sectionHeader("Welfare claims", "Follow each claim from submission through decision and payout.", right));
    const data = await K.api("claims.php?action=list");
    const fund = K.card("Welfare fund balance", "Available fund is calculated from the configured dues share, less claims paid.");
    fund.append(K.metric("Available", K.formatMoney(data.welfare_fund_balance)));
    root.append(fund);
    const panel = K.card("Claim register");
    const rows = data.claims.map((claim) => {
      const action = actions();
      if (user.role === "Welfare Officer" && claim.status === "Submitted") {
        action.append(K.button("Approve", async () => {
          const approved = window.prompt(`Approve up to ${K.formatMoney(claim.amount_requested)}. Enter approved amount:`, String(claim.amount_requested));
          if (approved === null) return;
          const reason = window.prompt("Decision note (optional):", "");
          try {
            await K.api("claims.php?action=decide", { method: "POST", body: JSON.stringify({ claim_id: claim.claim_id, decision: "approve", amount_approved: approved, decision_reason: reason || "" }) });
            K.toast("Claim approved.");
            window.location.reload();
          } catch (error) { K.toast(error.message, true); }
        }, "button button-small button-primary"));
        action.append(K.button("Reject", async () => {
          let reason = window.prompt("Reason for rejecting the claim:", Number(claim.arrears) > 0 ? "Dues arrears outstanding" : "");
          if (!reason) return;
          try {
            await K.api("claims.php?action=decide", { method: "POST", body: JSON.stringify({ claim_id: claim.claim_id, decision: "reject", decision_reason: reason }) });
            K.toast("Claim rejected with reason.");
            window.location.reload();
          } catch (error) { K.toast(error.message, true); }
        }, "button button-small button-danger"));
      }
      if (user.role === "Treasurer" && ["Approved", "Pending"].includes(claim.status)) {
        action.append(K.button(claim.status === "Pending" ? "Retry payout" : "Pay claim", async () => {
          try {
            const result = await K.api("claims.php?action=pay", { method: "POST", body: JSON.stringify({ claim_id: claim.claim_id }) });
            K.toast(result.message || "Claim paid.");
            window.location.reload();
          } catch (error) { K.toast(error.message, true); }
        }, "button button-small button-primary"));
      }
      return [
        claim.claim_id, claim.full_name, claim.claim_type, K.formatMoney(claim.amount_requested),
        claim.amount_approved == null ? "—" : K.formatMoney(claim.amount_approved),
        K.badge(claim.status), K.formatDate(claim.date_submitted), claim.decision_reason || "—", action,
      ];
    });
    panel.append(K.table(["Claim #", "Rider", "Type", "Requested", "Approved", "Status", "Submitted", "Decision note", "Action"], rows, "No welfare claims recorded."));
    root.append(panel);
  };

  renderers.claim_form = async (user, root) => {
    root.append(sectionHeader("Submit a welfare claim", "Claims are checked against current dues eligibility before an officer can approve them."));
    const panel = K.card("Claim details", "Available types: accident, sickness, funeral or emergency.");
    const fields = [];
    if (user.role !== "Rider") {
      const riders = await getRiders();
      fields.push({ label: "Rider", name: "rider_id", type: "select", options: { required: true, options: selectOptions(riders, "rider_id", "full_name") } });
    }
    fields.push(
      { label: "Claim type", name: "claim_type", type: "select", options: { required: true, options: [{ value: "", label: "Choose…" }, ...["Accident", "Sickness", "Funeral", "Emergency"].map((value) => ({ value, label: value }))] } },
      { label: "Amount requested (UGX)", name: "amount_requested", type: "number", options: { required: true, min: 1, step: 1000 } },
    );
    panel.append(K.form(fields, "Submit claim", async (data) => {
      const result = await K.api("claims.php?action=submit", { method: "POST", body: JSON.stringify(data) });
      if (!result.eligible) K.toast("Claim submitted, but approval will be blocked while dues arrears remain.", true);
      else K.toast("Welfare claim submitted.");
      window.location.assign(user.role === "Rider" ? "my_account.html" : "claims.html");
    }));
    root.append(panel);
  };

  renderers.compliance = async (user, root) => {
    root.append(sectionHeader("Compliance and incidents", "Track permit and insurance dates, expiry alerts and rider incidents."));
    const [documents, bikes, riders, incidents] = await Promise.all([
      K.api("permits.php?action=list"),
      K.api("motorcycles.php?action=list"),
      getRiders(),
      K.api("incidents.php?action=list"),
    ]);
    const alertPanel = K.card("Permit and insurance register", "Expired records and those inside the configured alert period appear first.");
    alertPanel.append(K.table(["Rider", "Plate", "Document", "Number", "Expiry", "Status"], documents.map((item) => [
      item.full_name, item.plate_number, item.doc_type, item.doc_number, K.formatDate(item.expiry_date),
      `${item.validity} · ${daysLabel(item.days_to_expiry)}`,
    ])));
    root.append(alertPanel);
    const addDoc = K.card("Record a permit or insurance document", "Each document record stays in the history for audit.");
    addDoc.append(K.form([
      { label: "Motorcycle", name: "motorcycle_id", type: "select", options: { required: true, options: selectOptions(bikes.map((bike) => ({ ...bike, bike_label: `${bike.plate_number} · ${bike.full_name}` })), "motorcycle_id", "bike_label") } },
      { label: "Document type", name: "doc_type", type: "select", options: { required: true, options: [{ value: "", label: "Choose…" }, { value: "Permit", label: "Permit" }, { value: "Insurance", label: "Insurance" }] } },
      { label: "Document number", name: "doc_number", options: { required: true } },
      { label: "Issue date", name: "issue_date", type: "date", options: { required: true } },
      { label: "Expiry date", name: "expiry_date", type: "date", options: { required: true } },
    ], "Save document", async (data) => {
      await K.api("permits.php?action=create", { method: "POST", body: JSON.stringify(data) });
      K.toast("Compliance document saved.");
      window.location.reload();
    }));
    root.append(addDoc);
    const incident = K.card("Record an incident", "Accidents, discipline actions and other stage incidents.");
    incident.append(K.form([
      { label: "Rider", name: "rider_id", type: "select", options: { required: true, options: selectOptions(riders, "rider_id", "full_name") } },
      { label: "Incident type", name: "incident_type", type: "select", options: { required: true, options: [{ value: "", label: "Choose…" }, { value: "Accident", label: "Accident" }, { value: "Discipline", label: "Discipline" }, { value: "Other", label: "Other" }] } },
      { label: "Date", name: "incident_date", type: "date", options: { required: true, value: new Date().toISOString().slice(0, 10) } },
      { label: "Description", name: "description", type: "textarea", options: { required: true, maxLength: 1000 } },
      { label: "Action taken", name: "action_taken", type: "textarea", options: { maxLength: 1000 } },
    ], "Save incident", async (data) => {
      await K.api("incidents.php?action=create", { method: "POST", body: JSON.stringify(data) });
      K.toast("Incident saved to the rider history.");
      window.location.reload();
    }));
    root.append(incident);
    const incidentList = K.card("Incident history");
    const filter = K.field("Filter history by rider", "incident_rider", "select", { options: selectOptions(riders, "rider_id", "full_name") });
    const incidentRows = K.card();
    const renderIncidents = (rows) => {
      incidentRows.replaceChildren();
      incidentRows.append(K.table(["Date", "Rider", "Type", "Description", "Action taken"], rows.map((item) => [
      K.formatDate(item.incident_date), item.full_name, item.incident_type, item.description, item.action_taken || "—",
      ])));
    };
    filter.querySelector("select").addEventListener("change", async () => {
      try {
        const riderId = filter.querySelector("select").value;
        const rows = await K.api(`incidents.php?action=list${riderId ? `&rider_id=${encodeURIComponent(riderId)}` : ""}`);
        renderIncidents(rows);
      } catch (error) { K.toast(error.message, true); }
    });
    incidentList.append(filter, incidentRows);
    renderIncidents(incidents);
    root.append(incidentList);
  };

  renderers.reports = async (user, root) => {
    root.append(sectionHeader("Collection and arrears reports", "Filter payment and welfare activity by date."));
    const panel = K.card("Report period");
    const filters = K.form([
      { label: "From", name: "from", type: "date", options: { required: true, value: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10) } },
      { label: "To", name: "to", type: "date", options: { required: true, value: new Date().toISOString().slice(0, 10) } },
    ], "Run report", async (data) => loadReport(data));
    panel.append(filters);
    root.append(panel);
    const resultPanel = K.card("Report results");
    root.append(resultPanel);
    async function loadReport(period) {
      resultPanel.replaceChildren();
      const data = await K.api(`reports.php?from=${encodeURIComponent(period.from)}&to=${encodeURIComponent(period.to)}`);
      resultPanel.append(K.text("h2", `Report · ${K.formatDate(data.from)} to ${K.formatDate(data.to)}`));
      resultPanel.append(K.table(["Date", "Payments", "Collected", "Cash", "Mobile money"], data.daily_collections.map((row) => [
        K.formatDate(row.payment_date), row.payment_count, K.formatMoney(row.total_collected), K.formatMoney(row.cash_total), K.formatMoney(row.mobile_money_total),
      ]), "No dues payments for this period."));
      const arrears = K.card("Riders with arrears", "Highest balance first.");
      arrears.append(K.table(["Rider", "Phone", "Arrears"], data.arrears.map((row) => [row.full_name, row.phone, K.formatMoney(row.arrears)]), "No arrears."));
      const claims = K.card("Claims by status");
      claims.append(K.table(["Status", "Claim count", "Requested", "Approved"], data.claims.map((row) => [K.badge(row.status), row.claim_count, K.formatMoney(row.requested), K.formatMoney(row.approved)]), "No claims in this period."));
      resultPanel.append(arrears, claims);
    }
    await loadReport({
      from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
      to: new Date().toISOString().slice(0, 10),
    });
  };

  renderers.strategic = async (user, root) => {
    const data = await K.api("strategic.php");
    root.append(sectionHeader("Strategic view", "Twelve-month indicators for stage leadership."));
    root.append(metricGrid([
      { label: "Arrears total", value: K.formatMoney(data.summary.arrears_total), note: "Across active riders" },
      { label: "Claims paid", value: K.formatMoney(data.summary.claims_paid_total), note: "Recorded welfare payout value" },
      { label: "Compliance rate", value: `${data.summary.compliance_rate}%`, note: "Active riders with current permit and insurance" },
      { label: "Collection rate this month", value: `${data.summary.collection_rate_month}%`, note: "Compared with four-week expected dues" },
    ]));
    const months = data.months || [];
    const grid = document.createElement("div");
    grid.className = "grid grid-2";
    grid.append(K.chart(
      "Collections vs expected (UGX)",
      months.map((row) => row.label.slice(0, 3)),
      months.map((row) => row.collections),
      "compare",
      months.map((row) => row.expected),
    ));
    grid.append(K.chart("New rider registrations", months.map((row) => row.label.slice(0, 3)), months.map((row) => row.new_members), "line"));
    grid.append(K.chart("Welfare claims paid (UGX)", months.map((row) => row.label.slice(0, 3)), months.map((row) => row.claims_paid), "bar"));
    root.append(grid);
    const figures = K.card("Twelve-month figures", "");
    figures.append(K.table(["Month", "Collected / expected", "New riders", "Claims paid"], months.map((row) => [
      row.label, `${K.formatMoney(row.collections)} / ${K.formatMoney(row.expected)}`, row.new_members, K.formatMoney(row.claims_paid),
    ])));
    root.append(figures);
  };

  renderers.settings = async (user, root) => {
    const settings = await K.api("settings.php");
    const values = Object.fromEntries(settings.map((row) => [row.setting_key, Number(row.setting_value)]));
    root.append(sectionHeader("Stage settings", "Weekly dues, arrears threshold and compliance/welfare rules."));
    const panel = K.card("Current settings", user.role === "Chairperson" ? "Only the Chairperson can change these values." : "Read-only settings.");
    const items = [
      ["weekly_dues", "Weekly dues (UGX)"],
      ["arrears_limit", "Arrears limit (UGX)"],
      ["alert_days", "Document alert lead time (days)"],
      ["welfare_share_percent", "Welfare share of dues (%)"],
    ];
    if (user.role === "Chairperson") {
      panel.append(K.form(items.map(([name, label]) => ({
        label, name, type: "number", options: { required: true, min: name === "welfare_share_percent" || name === "arrears_limit" ? 0 : 1, max: name === "welfare_share_percent" ? 100 : name === "alert_days" ? 365 : 100000000, step: 1, value: values[name] },
      })), "Save settings", async (data) => {
        await K.api("settings.php?action=update", { method: "POST", body: JSON.stringify(data) });
        K.toast("Settings updated and recorded in the audit log.");
        window.location.reload();
      }));
    } else {
      panel.append(K.table(["Setting", "Value"], items.map(([name, label]) => [label, values[name]])));
    }
    root.append(panel);
  };

  renderers.audit = async (user, root) => {
    root.append(sectionHeader("Audit log", "Read-only history of recorded changes and decisions."));
    const rows = await K.api("audit.php");
    const panel = K.card("Recent activity", "Showing the latest 500 entries.");
    panel.append(K.table(["Time", "Account", "Role", "Action", "Record type", "Record #", "Details"], rows.map((row) => [
      K.formatDate(row.created_at), row.username, row.role, row.action, row.table_name, row.record_id || "—", row.details || "—",
    ])));
    root.append(panel);
  };

  renderers.my_account = async (user, root) => {
    const data = await K.api("dashboard.php");
    root.append(sectionHeader(`Welcome, ${data.full_name}`, "Your personal stage account and welfare updates.", K.link("Submit a claim", "claim_form.html", "button button-primary")));
    root.append(metricGrid([
      { label: "Dues arrears", value: K.formatMoney(data.arrears), note: data.arrears > 0 ? "Please speak with the Treasurer about your balance." : "Your dues are up to date." },
      { label: "Savings balance", value: K.formatMoney(data.savings_balance), note: "See your savings statement for each transaction." },
      { label: "Membership", value: data.status, note: `Rider record #${data.rider_id}` },
    ]));
    const grid = document.createElement("div");
    grid.className = "grid grid-2";
    const payments = K.card("Recent dues receipts");
    payments.append(K.table(["Receipt #", "Date", "Period", "Amount", "Receipt"], (data.recent_payments || []).map((row) => [
      row.payment_id, K.formatDate(row.payment_date), row.period_covered, K.formatMoney(row.amount),
      K.link("Print", `receipt.html?id=${encodeURIComponent(row.payment_id)}`, "button button-small"),
    ]), "No dues receipts yet."));
    const claims = K.card("Welfare claim status", "Status updates are shown here when an official records a decision or payout.");
    claims.append(K.table(["Submitted", "Type", "Requested", "Approved", "Status", "Notice"], (data.claims || []).map((row) => [
      K.formatDate(row.date_submitted), row.claim_type, K.formatMoney(row.amount_requested),
      row.amount_approved == null ? "—" : K.formatMoney(row.amount_approved),
      K.badge(row.status), row.decision_reason || "—",
    ]), "You have not submitted a welfare claim."));
    grid.append(payments, claims);
    root.append(grid);
    const profile = K.card("My rider profile", "This record is visible only to your signed-in account.");
    profile.append(K.table(["National ID", "Phone", "Address", "Joined", "Next of kin", "Motorcycle plates"], [[
      data.national_id, data.phone, data.address, K.formatDate(data.date_joined),
      data.next_of_kin_name ? `${data.next_of_kin_name} · ${data.next_of_kin_phone || ""}` : "—",
      data.motorcycle_plates || "No motorcycles recorded",
    ]]));
    root.append(profile);
  };

  renderers.receipt = async (user, root) => {
    const id = new URLSearchParams(window.location.search).get("id");
    if (!id) throw new Error("A receipt number is required.");
    const row = await K.api(`dues.php?action=receipt&id=${encodeURIComponent(id)}`);
    root.append(sectionHeader("Payment receipt", "Kisenyi Central Boda-Boda Stage Association."));
    const panel = K.card();
    panel.classList.add("receipt");
    const header = document.createElement("div");
    header.className = "receipt-head";
    header.append(
      appendMany(document.createElement("div"), K.text("h2", "KCBSA"), K.text("p", "Kisenyi · Kampala · Uganda", "muted")),
      appendMany(document.createElement("div"), K.text("div", `Receipt #${row.payment_id}`, "receipt-number"), K.text("div", K.formatDate(row.payment_date), "muted")),
    );
    panel.append(header);
    panel.append(K.table(["Received from", "Phone", "Period covered", "Payment method", "Recorded by"], [[
      row.full_name, row.phone, row.period_covered, row.method, row.recorded_by_name,
    ]]));
    const total = document.createElement("div");
    total.className = "receipt-total";
    total.append(K.text("span", "Amount received"), K.text("strong", K.formatMoney(row.amount)));
    panel.append(total);
    panel.append(K.text("p", "Keep this receipt for your records.", "help"));
    panel.append(actions(K.button("Print receipt", () => window.print(), "button button-primary no-print"), K.link("Back to dues", "dues.html", "button no-print")));
    root.append(panel);
  };

  renderers.export = async (user, root) => {
    root.append(sectionHeader("Verified rider list", "Prepare a stage membership list for KCCA or Traffic Police."));
    const panel = K.card("Export options", "CSV uses UTF-8 with BOM and opens in spreadsheet software. The print view can be saved as PDF.");
    panel.append(actions(
      K.link("Download Excel-compatible CSV", "api/export.php?format=csv", "button button-primary"),
      K.button("Print / save as PDF", () => window.print(), "button"),
    ));
    root.append(panel);
    const data = await K.api("export.php");
    const list = K.card("Verified stage register", "Includes each rider, motorcycle plate, and document validity.");
    list.append(K.table(["Rider", "Phone", "Status", "Plate", "Permit expiry", "Insurance expiry"], data.map((row) => [
      row.full_name, row.phone, K.badge(row.status), row.plate_number || "—",
      `${row.permit_expiry || "Not recorded"} · ${row.permit_status}`,
      `${row.insurance_expiry || "Not recorded"} · ${row.insurance_status}`,
    ])));
    root.append(list);
    root.append(K.text("p", `Generated ${K.formatDate(new Date().toISOString())} · Kisenyi Central Boda-Boda Stage Association`, "help print-only"));
  };

  renderers.login = loginPage;
  window.KCBSAPages = {
    login: loginPage,
    render: async (page, user, root) => {
      if (!renderers[page]) throw new Error("This page is not available.");
      await renderers[page](user, root);
    },
  };
})();
