(function () {
  "use strict";

  const API_ROOT = "api/";
  let csrfToken = sessionStorage.getItem("kcbsa-csrf") || "";

  async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set("Accept", "application/json");
    if (options.body && !(options.body instanceof FormData)) {
      headers.set("Content-Type", "application/json");
    }
    if (csrfToken && ["POST", "PUT", "PATCH", "DELETE"].includes((options.method || "GET").toUpperCase())) {
      headers.set("X-CSRF-Token", csrfToken);
    }
    const response = await fetch(API_ROOT + path, {
      ...options,
      headers,
      credentials: "same-origin",
      cache: "no-store",
    });
    const contentType = response.headers.get("content-type") || "";
    if (!contentType.includes("application/json")) {
      throw new Error("The server returned an unexpected response. Check the PHP API setup.");
    }
    const result = await response.json();
    if (!response.ok || !result.success) {
      throw new Error(result.message || "The request could not be completed.");
    }
    return result.data;
  }

  const text = (tag, value, className) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    node.textContent = value == null ? "" : String(value);
    return node;
  };

  function link(label, href, className = "button") {
    const node = document.createElement("a");
    node.className = className;
    node.href = href;
    node.textContent = label;
    return node;
  }

  function button(label, onClick, className = "button") {
    const node = document.createElement("button");
    node.className = className;
    node.type = "button";
    node.textContent = label;
    node.addEventListener("click", onClick);
    return node;
  }

  function toast(message, isError = false) {
    let host = document.querySelector(".toast-host");
    if (!host) {
      host = document.createElement("div");
      host.className = "toast-host";
      host.setAttribute("aria-live", "polite");
      document.body.append(host);
    }
    const item = text("div", message, isError ? "toast error" : "toast");
    host.append(item);
    window.setTimeout(() => item.remove(), 4800);
  }

  function badge(value) {
    const normalized = String(value || "").toLowerCase();
    const kind = ["active", "paid", "valid", "approved"].includes(normalized)
      ? "good"
      : ["suspended", "rejected", "expired"].includes(normalized)
        ? "bad"
        : ["pending", "expiring soon", "submitted"].includes(normalized)
          ? "warn"
          : "info";
    return text("span", value, "badge " + kind);
  }

  function formatMoney(value) {
    return new Intl.NumberFormat("en-UG", {
      style: "currency",
      currency: "UGX",
      maximumFractionDigits: 0,
    }).format(Number(value || 0));
  }

  function formatDate(value) {
    if (!value) return "—";
    const date = new Date(String(value).replace(" ", "T"));
    return Number.isNaN(date.getTime())
      ? String(value)
      : new Intl.DateTimeFormat("en-UG", { day: "numeric", month: "short", year: "numeric" }).format(date);
  }

  function pageHeader(title, subtitle, actions) {
    const header = document.createElement("div");
    header.className = "page-heading";
    const left = document.createElement("div");
    left.append(text("h1", title), text("p", subtitle || ""));
    header.append(left);
    if (actions) header.append(actions);
    return header;
  }

  function card(title, subtitle) {
    const section = document.createElement("section");
    section.className = "card";
    if (title) {
      const header = document.createElement("div");
      header.className = "card-title";
      const copy = document.createElement("div");
      copy.append(text("h2", title));
      if (subtitle) copy.append(text("p", subtitle));
      header.append(copy);
      section.append(header);
    }
    return section;
  }

  function table(headers, rows, emptyMessage = "No records to show.") {
    const wrap = document.createElement("div");
    wrap.className = "table-wrap";
    if (!rows.length) {
      wrap.append(text("div", emptyMessage, "empty"));
      return wrap;
    }
    const tableNode = document.createElement("table");
    const thead = document.createElement("thead");
    const headRow = document.createElement("tr");
    headers.forEach((header) => headRow.append(text("th", header)));
    thead.append(headRow);
    const tbody = document.createElement("tbody");
    rows.forEach((cells) => {
      const row = document.createElement("tr");
      cells.forEach((cell) => {
        const td = document.createElement("td");
        if (cell instanceof Node) td.append(cell);
        else td.textContent = cell == null ? "" : String(cell);
        row.append(td);
      });
      tbody.append(row);
    });
    tableNode.append(thead, tbody);
    wrap.append(tableNode);
    return wrap;
  }

  function field(labelText, name, type = "text", options = {}) {
    const label = text("label", labelText);
    const input = document.createElement(type === "select" ? "select" : type === "textarea" ? "textarea" : "input");
    if (type !== "select" && type !== "textarea") input.type = type;
    input.name = name;
    if (options.required) input.required = true;
    if (options.maxLength) input.maxLength = options.maxLength;
    if (options.min != null) input.min = String(options.min);
    if (options.max != null) input.max = String(options.max);
    if (options.step != null) input.step = String(options.step);
    if (options.value != null) input.value = String(options.value);
    if (options.placeholder) input.placeholder = options.placeholder;
    if (options.autocomplete) input.autocomplete = options.autocomplete;
    if (options.options) {
      options.options.forEach((item) => {
        const option = text("option", item.label);
        option.value = item.value;
        input.append(option);
      });
    }
    label.append(input);
    return label;
  }

  function form(fields, submitLabel, onSubmit) {
    const node = document.createElement("form");
    node.className = "form-grid";
    fields.forEach((item) => {
      if (item instanceof Node) node.append(item);
      else node.append(field(item.label, item.name, item.type, item.options));
    });
    const actions = document.createElement("div");
    actions.className = "form-actions";
    const submit = text("button", submitLabel, "button button-primary");
    submit.type = "submit";
    actions.append(submit);
    node.append(actions);
    node.addEventListener("submit", async (event) => {
      event.preventDefault();
      submit.disabled = true;
      try {
        const data = Object.fromEntries(new FormData(node).entries());
        await onSubmit(data, node);
      } catch (error) {
        toast(error.message || "The form could not be submitted.", true);
      } finally {
        submit.disabled = false;
      }
    });
    return node;
  }

  function metric(label, value, note = "") {
    const node = card();
    node.classList.add("metric");
    node.append(text("div", label, "metric-label"), text("div", value, "metric-value"));
    if (note) node.append(text("div", note, "metric-note"));
    return node;
  }

  function chart(title, labels, values, mode = "bar", comparison = null) {
    const section = card(title);
    const width = 680;
    const height = 205;
    const pad = { left: 42, right: 14, top: 12, bottom: 37 };
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", `0 0 ${width} ${height}`);
    svg.setAttribute("role", "img");
    svg.setAttribute("aria-label", title);
    svg.classList.add("chart");
    const max = Math.max(1, ...values.map((value) => Number(value || 0)), ...(comparison || []).map((value) => Number(value || 0)));
    for (let tick = 0; tick <= 3; tick += 1) {
      const y = pad.top + ((height - pad.top - pad.bottom) * tick) / 3;
      const line = document.createElementNS(svg.namespaceURI, "line");
      line.setAttribute("x1", pad.left);
      line.setAttribute("x2", width - pad.right);
      line.setAttribute("y1", y);
      line.setAttribute("y2", y);
      line.setAttribute("class", "gridline");
      svg.append(line);
    }
    const count = Math.max(labels.length, 1);
    const innerWidth = width - pad.left - pad.right;
    const innerHeight = height - pad.top - pad.bottom;
    const points = [];
    const comparisonPoints = [];
    labels.forEach((label, index) => {
      const x = pad.left + (innerWidth * (index + 0.5)) / count;
      const value = Number(values[index] || 0);
      const y = pad.top + innerHeight - (value / max) * innerHeight;
      const labelNode = document.createElementNS(svg.namespaceURI, "text");
      labelNode.setAttribute("x", x);
      labelNode.setAttribute("y", height - 12);
      labelNode.setAttribute("text-anchor", "middle");
      labelNode.textContent = label;
      svg.append(labelNode);
      if (mode === "bar" || mode === "compare") {
        const bar = document.createElementNS(svg.namespaceURI, "rect");
        const barWidth = mode === "compare" ? Math.min(14, innerWidth / count / 4) : Math.min(28, innerWidth / count / 1.7);
        bar.setAttribute("x", x - barWidth / 2);
        bar.setAttribute("y", y);
        bar.setAttribute("width", barWidth);
        bar.setAttribute("height", Math.max(1, pad.top + innerHeight - y));
        bar.setAttribute("rx", "3");
        bar.setAttribute("class", "bar");
        svg.append(bar);
      }
      if (mode === "line") {
        points.push([x, y]);
      }
      if (mode === "compare" && comparison) {
        const comparisonValue = Number(comparison[index] || 0);
        const comparisonY = pad.top + innerHeight - (comparisonValue / max) * innerHeight;
        comparisonPoints.push([x, comparisonY]);
      }
    });
    if ((mode === "line" || mode === "compare") && points.length) {
      const path = document.createElementNS(svg.namespaceURI, "path");
      path.setAttribute("d", points.map((point, i) => `${i ? "L" : "M"} ${point[0]} ${point[1]}`).join(" "));
      path.setAttribute("class", "line");
      svg.append(path);
      points.forEach(([x, y]) => {
        const point = document.createElementNS(svg.namespaceURI, "circle");
        point.setAttribute("cx", x);
        point.setAttribute("cy", y);
        point.setAttribute("r", "4");
        point.setAttribute("class", "point");
        svg.append(point);
      });
    }
    if (comparisonPoints.length) {
      const path = document.createElementNS(svg.namespaceURI, "path");
      path.setAttribute("d", comparisonPoints.map((point, i) => `${i ? "L" : "M"} ${point[0]} ${point[1]}`).join(" "));
      path.setAttribute("class", "line secondary");
      svg.append(path);
      comparisonPoints.forEach(([x, y]) => {
        const point = document.createElementNS(svg.namespaceURI, "circle");
        point.setAttribute("cx", x);
        point.setAttribute("cy", y);
        point.setAttribute("r", "4");
        point.setAttribute("class", "point secondary");
        svg.append(point);
      });
    }
    section.append(svg);
    if (mode === "compare") {
      const legend = document.createElement("div");
      legend.className = "chart-legend";
      const collected = document.createElement("span");
      collected.append(text("i", "", "legend-mark legend-bar"), text("span", "Collected"));
      const expected = document.createElement("span");
      expected.append(text("i", "", "legend-mark legend-line"), text("span", "Expected"));
      legend.append(collected, expected);
      section.append(legend);
    }
    return section;
  }

  function notice(message, kind = "info") {
    return text("div", message, kind === "danger" ? "notice-box" : "help");
  }

  function setCsrf(token) {
    csrfToken = token || "";
    if (csrfToken) sessionStorage.setItem("kcbsa-csrf", csrfToken);
    else sessionStorage.removeItem("kcbsa-csrf");
  }

  function navItems(role) {
    const common = [["Overview", "dashboard.html", "dashboard"]];
    const byRole = {
      Secretary: [...common, ["Riders", "riders.html", "riders"], ["Dues", "dues.html", "dues"], ["Verified list", "export.html", "export"]],
      Treasurer: [...common, ["Dues", "dues.html", "dues"], ["Savings", "savings.html", "savings"], ["Welfare claims", "claims.html", "claims"], ["Reports", "reports.html", "reports"]],
      "Welfare Officer": [...common, ["Welfare claims", "claims.html", "claims"]],
      "Safety Officer": [...common, ["Compliance", "compliance.html", "compliance"]],
      Chairperson: [...common, ["Riders", "riders.html", "riders"], ["Dues", "dues.html", "dues"], ["Savings", "savings.html", "savings"], ["Welfare claims", "claims.html", "claims"], ["Compliance", "compliance.html", "compliance"], ["Reports", "reports.html", "reports"], ["Strategic view", "strategic.html", "strategic"], ["Settings", "settings.html", "settings"], ["Audit log", "audit.html", "audit"], ["Verified list", "export.html", "export"]],
      Rider: [["My account", "my_account.html", "my_account"], ["Savings statement", "savings.html", "savings"], ["Submit a claim", "claim_form.html", "claim_form"]],
    };
    return byRole[role] || common;
  }

  function buildShell(user, page) {
    const root = document.getElementById("app");
    root.replaceChildren();
    const shell = document.createElement("div");
    shell.className = "app-shell";
    const sidebar = document.createElement("aside");
    sidebar.className = "sidebar";
    const brand = document.createElement("div");
    brand.className = "side-brand";
    const mark = text("div", "K", "brand-mark");
    mark.setAttribute("aria-hidden", "true");
    const brandCopy = document.createElement("div");
    brandCopy.append(text("div", "KCBSA MIS", "side-title"), text("div", "Kisenyi · Kampala", "side-subtitle"));
    brand.append(mark, brandCopy);
    sidebar.append(brand, text("div", "Stage operations", "nav-label"));
    const nav = document.createElement("nav");
    nav.className = "nav-list";
    navItems(user.role).forEach(([label, href, key]) => {
      const item = link(label, href, key === page ? "nav-link active" : "nav-link");
      if (key === page) item.setAttribute("aria-current", "page");
      nav.append(item);
    });
    sidebar.append(nav);
    const bottom = document.createElement("div");
    bottom.className = "side-bottom";
    const account = document.createElement("div");
    account.className = "user-badge";
    account.append(text("strong", user.username), text("span", user.role));
    bottom.append(account, button("Sign out", async () => {
      try {
        await api("auth.php?action=logout", { method: "POST", body: JSON.stringify({}) });
      } catch (_) {
        // The local session will still be discarded on the sign-in page.
      }
      setCsrf("");
      window.location.replace("login.html");
    }, "nav-link"));
    sidebar.append(bottom);

    const main = document.createElement("main");
    main.className = "main-area";
    const topbar = document.createElement("header");
    topbar.className = "topbar";
    const menu = button("Menu", () => sidebar.classList.toggle("open"), "button mobile-menu");
    menu.setAttribute("aria-label", "Open navigation");
    const current = text("h1", page.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()));
    const right = document.createElement("div");
    right.className = "topbar-right";
    right.append(badge(user.role));
    topbar.append(menu, current, right);
    const content = document.createElement("div");
    content.className = "content";
    main.append(topbar, content);
    shell.append(sidebar, main);
    root.append(shell);
    return content;
  }

  function allowed(role, page) {
    if (role === "Rider") return ["my_account", "savings", "claim_form", "receipt"].includes(page);
    const roles = {
      dashboard: ["Secretary", "Treasurer", "Welfare Officer", "Safety Officer", "Chairperson"],
      riders: ["Secretary", "Treasurer", "Welfare Officer", "Safety Officer", "Chairperson"],
      rider_form: ["Secretary", "Chairperson"],
      rider_view: ["Secretary", "Chairperson"],
      dues: ["Secretary", "Treasurer", "Chairperson"],
      savings: ["Treasurer", "Chairperson"],
      claims: ["Treasurer", "Welfare Officer", "Chairperson"],
      claim_form: ["Secretary", "Treasurer", "Welfare Officer", "Safety Officer", "Chairperson"],
      compliance: ["Safety Officer", "Chairperson"],
      reports: ["Treasurer", "Chairperson"],
      strategic: ["Chairperson"],
      settings: ["Secretary", "Treasurer", "Welfare Officer", "Safety Officer", "Chairperson"],
      audit: ["Chairperson"],
      export: ["Secretary", "Chairperson"],
      receipt: ["Secretary", "Treasurer", "Chairperson"],
    };
    return (roles[page] || []).includes(role);
  }

  async function boot() {
    const page = window.KCBSA_PAGE || document.body.dataset.page || "dashboard";
    if (page === "login") {
      if (window.KCBSAPages) window.KCBSAPages.login();
      return;
    }
    let auth;
    try {
      auth = await api("auth.php?action=me");
    } catch (error) {
      window.location.replace("login.html");
      return;
    }
    setCsrf(auth.csrf_token);
    const user = auth.user;
    if (!user) {
      window.location.replace("login.html");
      return;
    }
    if (user.role === "Rider" && page === "dashboard") {
      window.location.replace("my_account.html");
      return;
    }
    if (!allowed(user.role, page)) {
      window.location.replace(user.role === "Rider" ? "my_account.html" : "dashboard.html");
      return;
    }
    const content = buildShell(user, page);
    try {
      await window.KCBSAPages.render(page, user, content);
    } catch (error) {
      content.append(text("div", error.message || "Could not load this page.", "notice-box"));
    }
  }

  window.KCBSA = {
    api, text, link, button, toast, badge, formatMoney, formatDate, pageHeader, card, table,
    field, form, metric, chart, notice, setCsrf, buildShell,
  };
  document.addEventListener("DOMContentLoaded", boot);
})();
