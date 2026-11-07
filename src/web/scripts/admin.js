(function () {
    const pageSizes = [10, 30, 50, 80, 100];

    function toast(message, type) {
        const stack = document.getElementById("toasts");
        if (!stack || !message) {
            return;
        }
        const node = document.createElement("div");
        node.className = "app-toast" + (type === "danger" ? " danger" : "");
        node.setAttribute("role", "status");
        node.textContent = message;
        stack.appendChild(node);
        window.setTimeout(function () {
            node.remove();
        }, 4300);
    }
    window.toast = toast;

    document.querySelectorAll(".toast-seed").forEach(function (node) {
        toast(node.dataset.message || "", node.dataset.type || "success");
    });

    const collapseKey = "bms.sidebar";
    const desktop = window.matchMedia("(min-width: 992px)");
    if (desktop.matches && localStorage.getItem(collapseKey) === "closed") {
        document.body.classList.add("sidebar-collapsed");
    }
    const collapse = document.getElementById("collapse-nav");
    function syncCollapse() {
        if (!collapse) {
            return;
        }
        collapse.setAttribute("aria-expanded", document.body.classList.contains("sidebar-collapsed") ? "false" : "true");
    }
    syncCollapse();
    if (collapse) {
        collapse.addEventListener("click", function () {
            document.body.classList.toggle("sidebar-collapsed");
            localStorage.setItem(collapseKey, document.body.classList.contains("sidebar-collapsed") ? "closed" : "open");
            syncCollapse();
        });
    }
    const menu = document.getElementById("menu-button");
    const backdrop = document.getElementById("sidebar-backdrop");
    function closeMobile() {
        document.body.classList.remove("nav-open");
        if (backdrop) {
            backdrop.hidden = true;
        }
    }
    if (menu) {
        menu.addEventListener("click", function () {
            if (desktop.matches) {
                document.body.classList.remove("sidebar-collapsed");
                localStorage.setItem(collapseKey, "open");
                return;
            }
            const open = document.body.classList.toggle("nav-open");
            if (backdrop) {
                backdrop.hidden = !open;
            }
        });
    }
    if (backdrop) {
        backdrop.addEventListener("click", closeMobile);
    }

    document.addEventListener("click", function (event) {
        const passwordButton = event.target.closest("[data-toggle-password]");
        if (passwordButton) {
            const input = document.getElementById(passwordButton.getAttribute("data-toggle-password"));
            if (!input) {
                return;
            }
            const show = input.type === "password";
            input.type = show ? "text" : "password";
            input.classList.toggle("is-revealed", show);
            const icon = passwordButton.querySelector("i");
            if (icon) {
                icon.className = "fa-regular " + (show ? "fa-eye" : "fa-eye-slash");
            }
            passwordButton.setAttribute("aria-label", show ? "Hide password" : "Show password");
            const box = input.closest(".password-box");
            const beam = box ? box.querySelector(".beam") : null;
            if (beam) {
                document.querySelectorAll(".password-box .beam").forEach(function (node) {
                    node.hidden = true;
                });
                beam.hidden = !show;
                if (!show) {
                    const other = document.querySelector(".password-box input.is-revealed");
                    const otherBeam = other && other.parentElement ? other.parentElement.querySelector(".beam") : null;
                    if (otherBeam) {
                        otherBeam.hidden = false;
                    }
                }
            }
            document.body.classList.toggle("show-password", document.querySelector(".password-box input.is-revealed") !== null);
            input.focus();
            return;
        }
        const copyButton = event.target.closest("[data-copy]");
        if (copyButton) {
            const value = copyButton.getAttribute("data-copy") || "";
            if (navigator.clipboard) {
                navigator.clipboard.writeText(value).then(function () {
                    toast("Copied", "success");
                });
            }
            return;
        }
        const reset = event.target.closest("[data-reset]");
        if (reset) {
            const form = reset.closest("form");
            if (!form) {
                return;
            }
            form.querySelectorAll("[data-initial]").forEach(function (field) {
                if (field.type === "checkbox") {
                    field.checked = field.dataset.initial === "1";
                } else if (field.type === "radio") {
                    field.checked = field.dataset.initial === "1";
                } else {
                    field.value = field.dataset.initial || "";
                }
            });
            clearErrors(form);
            syncMatch(form);
        }
    });

    document.addEventListener("mousemove", function (event) {
        if (!document.body.classList.contains("show-password")) {
            return;
        }
        const beam = document.querySelector(".password-box .beam:not([hidden])");
        if (!beam) {
            return;
        }
        const rect = beam.getBoundingClientRect();
        const mouseX = rect.right + (rect.width / 2);
        const mouseY = rect.top + (rect.height / 2);
        const rad = Math.atan2(mouseX - event.pageX, mouseY - event.pageY);
        const deg = (rad * (20 / Math.PI) * -1) - 350;
        document.body.style.setProperty("--beam-deg", deg + "deg");
    });

    document.addEventListener("input", function (event) {
        const field = event.target;
        if (field.matches && field.matches("[data-match-input]")) {
            syncMatch(field.form);
        }
        if (field.id === "slot_length_min") {
            const note = document.getElementById("slot-length-note");
            const minutes = parseInt(field.value, 10);
            if (note) {
                note.textContent = minutes > 0 ? "Each group sits for " + minutes + " minutes" : "";
            }
        }
        if (field.closest && field.closest(".generator-grid")) {
            updatePlan();
        }
    });

    function syncMatch(form) {
        if (!form) {
            return;
        }
        const input = form.querySelector("[data-match-input]");
        const button = form.querySelector('[type="submit"]');
        if (!input || !button) {
            return;
        }
        button.disabled = input.value !== input.dataset.match;
    }

    function clearErrors(form) {
        form.querySelectorAll(".is-invalid").forEach(function (field) {
            field.classList.remove("is-invalid");
        });
        form.querySelectorAll(".js-error").forEach(function (node) {
            node.remove();
        });
    }

    function showErrors(form, errors) {
        if (!errors) {
            return;
        }
        let focused = false;
        Object.keys(errors).forEach(function (name) {
            const field = form.elements[name];
            if (!field) {
                return;
            }
            const target = field.length && !field.tagName ? field[0] : field;
            target.classList.add("is-invalid");
            const note = document.createElement("div");
            note.className = "invalid-feedback d-block js-error";
            note.textContent = errors[name];
            const slot = target.closest(".password-box") || target;
            slot.insertAdjacentElement("afterend", note);
            if (!focused) {
                target.focus();
                focused = true;
            }
        });
    }

    function busy(button, on) {
        if (!button) {
            return;
        }
        if (on) {
            button.dataset.original = button.innerHTML;
            const label = button.dataset.busyLabel || "Saving";
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + label;
        } else {
            button.innerHTML = button.dataset.original || button.innerHTML;
            if (!button.form || !button.form.querySelector("[data-match-input]")) {
                button.disabled = false;
            } else {
                syncMatch(button.form);
            }
        }
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && document.querySelector('.js-form[data-busy="1"]')) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    // An input named action shadows the form action URL, so form.action is that element.
    function formUrl(form) {
        return form.getAttribute("action") || window.location.href;
    }

    document.addEventListener("submit", function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (form.classList.contains("login-form")) {
            const missing = firstMissing(form);
            if (missing) {
                event.preventDefault();
                showLoginMissing(form, missing);
            }
            return;
        }
        if (form.classList.contains("js-download")) {
            event.preventDefault();
            downloadForm(form);
            return;
        }
        if (!form.classList.contains("js-form")) {
            return;
        }
        event.preventDefault();
        if (form.dataset.busy === "1") {
            return;
        }
        const button = event.submitter || form.querySelector('[type="submit"]');
        form.dataset.busy = "1";
        busy(button, true);
        clearErrors(form);
        const payload = new FormData(form);
        if (event.submitter && event.submitter.name) {
            payload.set(event.submitter.name, event.submitter.value);
        }
        fetch(formUrl(form), {
            method: "POST",
            headers: { Accept: "application/json", "X-Requested-With": "fetch" },
            body: payload
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (data) {
            if (!data) {
                throw new Error("network");
            }
            if (!data.ok) {
                showErrors(form, data.errors || {});
                if (data.message) {
                    toast(data.message, "danger");
                }
                return;
            }
            if (data.secret) {
                showSecret(form, data.secret);
                return;
            }
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            const next = data.reload || window.location.href;
            window.location.href = next;
        }).catch(function () {
            toast("Not saved. Check the connection and try again.", "danger");
        }).finally(function () {
            if (form.dataset.keep === "1") {
                return;
            }
            form.dataset.busy = "0";
            busy(button, false);
        });
    });

    function showSecret(form, secret) {
        form.dataset.keep = "1";
        const modal = form.closest(".modal");
        const body = form.querySelector(".modal-body");
        const footer = form.querySelector(".modal-footer");
        if (body) {
            body.innerHTML = "<p>" + escapeHtml(secret.message || "Copy this password now. It will not be shown again.") + "</p>"
                + '<p class="reference-line"><code id="secret-value"></code> '
                + '<button type="button" class="btn btn-link" data-copy="">Copy</button></p>';
            body.querySelector("#secret-value").textContent = secret.password || "";
            body.querySelector("[data-copy]").setAttribute("data-copy", secret.password || "");
        }
        if (footer) {
            footer.innerHTML = '<button type="button" class="btn btn-poly" data-bs-dismiss="modal">Close</button>';
        }
        if (modal) {
            modal.addEventListener("hidden.bs.modal", function () {
                window.location.reload();
            }, { once: true });
        }
    }

    function downloadForm(form) {
        const button = form.querySelector('[type="submit"]');
        form.dataset.busy = "1";
        busy(button, true);
        fetch(formUrl(form), {
            method: "POST",
            headers: { "X-Requested-With": "fetch" },
            body: new FormData(form)
        }).then(function (response) {
            const type = response.headers.get("Content-Type") || "";
            if (type.indexOf("application/json") !== -1) {
                return response.json().then(function (data) {
                    toast((data && data.message) || "Not saved. Check the connection and try again.", "danger");
                });
            }
            if (!response.ok) {
                throw new Error("download");
            }
            const match = /filename="([^"]+)"/.exec(response.headers.get("Content-Disposition") || "");
            const filename = match ? match[1] : "credentials.xlsx";
            return response.blob().then(function (blob) {
                const link = document.createElement("a");
                link.href = URL.createObjectURL(blob);
                link.download = filename;
                link.click();
                URL.revokeObjectURL(link.href);
                window.location.reload();
            });
        }).catch(function () {
            toast("Not saved. Check the connection and try again.", "danger");
        }).finally(function () {
            form.dataset.busy = "0";
            busy(button, false);
        });
    }

    function firstMissing(form) {
        const name = form.querySelector('[name="login_name"]');
        const password = form.querySelector('[name="password"]');
        const kind = form.querySelector('[name="login_as"]');
        if (name && name.value.trim() === "") {
            return name;
        }
        if (password && password.value === "") {
            return password;
        }
        if (kind && (kind.value === "" || kind.value === "choose")) {
            return kind;
        }
        return null;
    }

    function showLoginMissing(form, field) {
        clearErrors(form);
        const messages = {
            login_name: "Enter your login name.",
            password: "Enter your password.",
            login_as: "Select teacher or student."
        };
        const errors = {};
        errors[field.name] = messages[field.name];
        showErrors(form, errors);
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char];
        });
    }

    if (window.jQuery && window.jQuery.fn.bootstrapTable) {
        window.jQuery.extend(window.jQuery.fn.bootstrapTable.defaults, {
            pagination: true,
            search: true,
            sortable: true,
            showColumns: true,
            pageSize: 30,
            pageList: pageSizes,
            searchTimeOut: 300,
            formatShowingRows: function (from, to, total) {
                return "Showing " + from + " to " + to + " of " + total;
            },
            formatNoMatches: function () {
                return "No rows match this search. <button type=\"button\" class=\"btn btn-link clear-search\">Clear search</button>";
            },
            loadingTemplate: function () {
                return '<div class="skeleton" aria-hidden="true"><span></span><span></span><span></span></div>';
            }
        });
    }

    document.addEventListener("click", function (event) {
        if (!event.target.closest(".clear-search")) {
            return;
        }
        const table = window.jQuery(event.target).closest(".bootstrap-table").find("table");
        table.bootstrapTable("resetSearch", "");
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Enter" || !event.target.classList.contains("search-input")) {
            return;
        }
        event.preventDefault();
        const table = window.jQuery(event.target).closest(".bootstrap-table").find("table");
        table.bootstrapTable("resetSearch", event.target.value);
    });

    function currentFilter() {
        const active = document.querySelector(".filter-bar [data-filter].active");
        return active ? active.dataset.filter : "all";
    }

    function rememberTable(table) {
        const options = table.bootstrapTable("getOptions");
        const url = new URL(window.location.href);
        const search = options.searchText || "";
        if (search) {
            url.searchParams.set("search", search);
        } else {
            url.searchParams.delete("search");
        }
        if (options.sortName) {
            url.searchParams.set("sort", options.sortName);
        }
        if (options.sortOrder) {
            url.searchParams.set("order", options.sortOrder);
        }
        url.searchParams.set("page", String(options.pageNumber || 1));
        url.searchParams.set("pageSize", String(options.pageSize || 30));
        const filter = currentFilter();
        if (filter && filter !== "all") {
            url.searchParams.set("filter", filter);
        } else {
            url.searchParams.delete("filter");
        }
        window.history.replaceState(null, "", url);
    }

    function highlightRow() {
        const flash = new URL(window.location.href).searchParams.get("flash_row");
        if (!flash) {
            return;
        }
        document.querySelectorAll('tr[data-uniqueid="' + CSS.escape(flash) + '"], tr[data-id="' + CSS.escape(flash) + '"]').forEach(function (row) {
            row.classList.add("row-saved");
        });
    }

    window.mountServerTable = function (selector, options) {
        const url = new URL(window.location.href);
        const useUrl = options.useUrl !== false;
        const pageSize = pageSizes.indexOf(parseInt(url.searchParams.get("pageSize") || "30", 10)) >= 0
            ? parseInt(url.searchParams.get("pageSize") || "30", 10)
            : 30;
        const table = window.jQuery(selector);
        const config = {
            url: options.url,
            sidePagination: "server",
            uniqueId: "id",
            idField: "id",
            searchText: useUrl ? (url.searchParams.get("search") || "") : "",
            sortName: useUrl ? (url.searchParams.get("sort") || options.sortName) : options.sortName,
            sortOrder: useUrl ? (url.searchParams.get("order") || options.sortOrder) : options.sortOrder,
            pageNumber: useUrl ? Math.max(1, parseInt(url.searchParams.get("page") || "1", 10)) : 1,
            pageSize: useUrl ? pageSize : 30,
            columns: options.columns,
            queryParams: function (params) {
                params.ajax = options.ajax || "rows";
                params.filter = options.filters === false ? "all" : currentFilter();
                return params;
            },
            onLoadSuccess: function (data) {
                if (options.remember !== false) {
                    rememberTable(table);
                }
                const host = document.querySelector(options.emptyHost || "#list-empty");
                const error = document.querySelector(options.errorHost || "#list-error");
                const wrapper = table.closest(".bootstrap-table");
                if (error) {
                    error.hidden = true;
                }
                if (data && data.total === 0 && data.emptyHtml && host) {
                    host.innerHTML = data.emptyHtml;
                    host.hidden = false;
                    if (wrapper.length) {
                        wrapper[0].hidden = true;
                    }
                } else if (host) {
                    host.hidden = true;
                    if (wrapper.length) {
                        wrapper[0].hidden = false;
                    }
                }
                if (data && typeof data.scheduleHtml === "string") {
                    const schedule = document.getElementById(options.schedule || "schedule");
                    if (schedule) {
                        schedule.innerHTML = data.scheduleHtml;
                    }
                }
                highlightRow();
            },
            onLoadError: function () {
                const error = document.querySelector(options.errorHost || "#list-error");
                if (error) {
                    error.hidden = false;
                }
            }
        };
        Object.keys(options.extra || {}).forEach(function (key) {
            config[key] = options.extra[key];
        });
        table.bootstrapTable(config);
        table.on("click-row.bs.table", function (event, row, element) {
            if (window.jQuery(event.target).closest("a,button,input,select,label").length) {
                return;
            }
            if (options.expandDetail) {
                table.bootstrapTable("toggleDetailView", element.data("index"));
                return;
            }
            if (options.detailUrl && row.id) {
                openDetail(options.detailUrl, row.id);
                return;
            }
            if (options.onRow) {
                options.onRow(row, element);
            }
        });
        if (options.filters !== false) {
            document.querySelectorAll(".filter-bar [data-filter]").forEach(function (button) {
                button.addEventListener("click", function () {
                    document.querySelectorAll(".filter-bar [data-filter]").forEach(function (other) {
                        other.classList.remove("active");
                        other.setAttribute("aria-pressed", "false");
                    });
                    button.classList.add("active");
                    button.setAttribute("aria-pressed", "true");
                    table.bootstrapTable("refresh", { pageNumber: 1 });
                });
            });
        }
        if (!window.__bmsServerTables) {
            window.__bmsServerTables = [];
        }
        window.__bmsServerTables.push(table);
        if (options.retry !== false) {
            const retry = document.querySelector(options.retryHost || ".retry-list");
            if (retry && retry.dataset.bound !== "1") {
                retry.dataset.bound = "1";
                retry.addEventListener("click", function () {
                    window.__bmsServerTables.forEach(function (item) {
                        item.bootstrapTable("refresh");
                    });
                });
            }
        }
        if (options.detailUrl && options.openFromQuery !== false) {
            const detailId = url.searchParams.get("detail");
            if (detailId) {
                openDetail(options.detailUrl, detailId);
            }
        }
    };

    window.slotRemoveCheckbox = function (value, row) {
        const extra = row && row._data ? row._data : {};
        if (String(extra.removable) === "0") {
            return { disabled: true };
        }
        return value;
    };

    window.slotTimeSorter = function (a, b, rowA, rowB) {
        const left = String((rowA && rowA._data && rowA._data.start) || "");
        const right = String((rowB && rowB._data && rowB._data.start) || "");
        if (left !== right) {
            return left < right ? -1 : 1;
        }
        const first = parseInt((rowA && rowA._data && rowA._data.number) || "0", 10);
        const second = parseInt((rowB && rowB._data && rowB._data.number) || "0", 10);
        return first - second;
    };

    window.slotNumberSorter = function (a, b) {
        return parseInt(a, 10) - parseInt(b, 10);
    };

    function bindFileDrop() {
        document.querySelectorAll(".file-drop").forEach(function (zone) {
            const input = zone.querySelector('input[type="file"]');
            const note = zone.querySelector(".file-drop-note");
            if (!input) {
                return;
            }
            const idle = note ? note.textContent : "";
            let depth = 0;
            function showIdle() {
                if (!note) {
                    return;
                }
                if (input.files && input.files[0]) {
                    note.textContent = input.files[0].name;
                } else {
                    note.textContent = idle;
                }
            }
            zone.addEventListener("dragenter", function (event) {
                event.preventDefault();
                depth += 1;
                zone.classList.add("is-dragover");
                if (note) {
                    note.textContent = "Drop the spreadsheet";
                }
            });
            zone.addEventListener("dragover", function (event) {
                event.preventDefault();
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = "copy";
                }
            });
            zone.addEventListener("dragleave", function () {
                depth -= 1;
                if (depth <= 0) {
                    depth = 0;
                    zone.classList.remove("is-dragover");
                    showIdle();
                }
            });
            zone.addEventListener("drop", function (event) {
                event.preventDefault();
                depth = 0;
                zone.classList.remove("is-dragover");
                const files = event.dataTransfer ? event.dataTransfer.files : null;
                const file = files && files.length > 0 ? files[0] : null;
                if (!file) {
                    showIdle();
                    return;
                }
                if (!file.name.toLowerCase().endsWith(".xlsx")) {
                    showIdle();
                    toast("Choose an .xlsx spreadsheet.", "danger");
                    return;
                }
                const transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
                input.dispatchEvent(new Event("change", { bubbles: true }));
                showIdle();
            });
            input.addEventListener("change", showIdle);
        });
    }

    function bindSlotRemoval() {
        const button = document.getElementById("remove-selected");
        const form = document.getElementById("remove-slots");
        const tableEl = document.getElementById("slot-table");
        if (!button || !form || !tableEl || !window.jQuery) {
            return;
        }
        const table = window.jQuery(tableEl);
        function selectedIds() {
            const rows = table.bootstrapTable("getSelections") || [];
            const ids = [];
            rows.forEach(function (row) {
                const extra = row._data || {};
                if (String(extra.removable) === "0") {
                    return;
                }
                const id = String(row.id || extra.id || "");
                if (id !== "") {
                    ids.push(id);
                }
            });
            return ids;
        }
        function syncButton() {
            const count = selectedIds().length;
            button.disabled = count === 0;
            button.textContent = count === 0
                ? "Remove selected"
                : "Remove " + count + (count === 1 ? " time slot" : " time slots");
        }
        table.on("check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table page-change.bs.table", syncButton);
        button.addEventListener("click", function () {
            const ids = selectedIds();
            if (ids.length === 0) {
                toast("Select at least one time slot.", "danger");
                return;
            }
            form.querySelectorAll("[data-slot-id]").forEach(function (node) {
                node.remove();
            });
            ids.forEach(function (id) {
                const input = document.createElement("input");
                input.type = "hidden";
                input.name = "timeslot_id[]";
                input.value = id;
                input.setAttribute("data-slot-id", "1");
                form.appendChild(input);
            });
            form.requestSubmit();
        });
    }

    function bindClientFilters() {
        document.querySelectorAll(".filter-bar [data-filter]").forEach(function (button) {
            if (button.dataset.boundClientFilter === "1") {
                return;
            }
            button.dataset.boundClientFilter = "1";
            button.addEventListener("click", function () {
                document.querySelectorAll(".filter-bar [data-filter]").forEach(function (other) {
                    other.classList.remove("active");
                    other.setAttribute("aria-pressed", "false");
                });
                button.classList.add("active");
                button.setAttribute("aria-pressed", "true");
                document.querySelectorAll("[data-client-table]").forEach(function (el) {
                    if (el.getAttribute("data-ignore-filter") === "true") {
                        return;
                    }
                    const item = window.jQuery(el);
                    if (!item.data("bootstrap.table")) {
                        return;
                    }
                    item.bootstrapTable("resetSearch", item.bootstrapTable("getOptions").searchText || "");
                    rememberTable(item);
                });
            });
        });
    }

    window.mountClientTable = function (selector) {
        const table = window.jQuery(selector);
        if (!table.length || table.data("bootstrap.table")) {
            return;
        }
        const ignoreFilter = table.attr("data-ignore-filter") === "true";
        const url = new URL(window.location.href);
        const pageSize = pageSizes.indexOf(parseInt(url.searchParams.get("pageSize") || "30", 10)) >= 0
            ? parseInt(url.searchParams.get("pageSize") || "30", 10)
            : 30;
        table.bootstrapTable({
            uniqueId: "id",
            searchText: ignoreFilter ? "" : (url.searchParams.get("search") || ""),
            sortName: table.data("sortName") || undefined,
            sortOrder: table.data("sortOrder") || undefined,
            pageNumber: ignoreFilter ? 1 : Math.max(1, parseInt(url.searchParams.get("page") || "1", 10)),
            pageSize: ignoreFilter ? 30 : pageSize,
            customSearch: function (data, text) {
                const filter = ignoreFilter ? "all" : currentFilter();
                return data.filter(function (row) {
                    const marker = String(row.filter || row.status_value || (row._data && row._data.filter) || "");
                    if (filter !== "all" && marker !== filter) {
                        return false;
                    }
                    if (!text) {
                        return true;
                    }
                    return JSON.stringify(row).toLowerCase().indexOf(String(text).toLowerCase()) !== -1;
                });
            }
        });
        const stored = table.bootstrapTable("getOptions");
        let stamped = false;
        (stored.data || []).forEach(function (row) {
            const extra = row._data || {};
            if (!row.id && extra.id != null && String(extra.id) !== "") {
                row.id = String(extra.id);
                stamped = true;
            }
            if ((row.filter == null || row.filter === "") && extra.filter != null && String(extra.filter) !== "") {
                row.filter = String(extra.filter);
                stamped = true;
            }
        });
        if (stamped) {
            const page = stored.pageNumber || 1;
            table.bootstrapTable("load", stored.data);
            if (page > 1) {
                table.bootstrapTable("selectPage", page);
            }
        }
        if (!ignoreFilter && (stamped || currentFilter() !== "all")) {
            const page = table.bootstrapTable("getOptions").pageNumber || 1;
            table.bootstrapTable("resetSearch", table.bootstrapTable("getOptions").searchText || "");
            if (page > 1) {
                table.bootstrapTable("selectPage", page);
            }
        }
        if (!ignoreFilter) {
            table.on("page-change.bs.table sort.bs.table search.bs.table", function () {
                rememberTable(table);
            });
        }
        bindClientFilters();
        table.on("click-row.bs.table", function (event, row) {
            if (window.jQuery(event.target).closest("a,button,input,select,label,form").length) {
                return;
            }
            const detailUrl = table.attr("data-detail-url");
            const extra = row && row._data ? row._data : {};
            const id = extra.id != null && String(extra.id) !== "" ? String(extra.id) : (row && row.id ? String(row.id) : "");
            if (detailUrl && id) {
                openDetail(detailUrl, id);
            }
        });
        highlightRow();
    };

    function openDetail(url, id) {
        const canvas = document.getElementById("detail-canvas");
        if (!canvas) {
            return;
        }
        const target = new URL(url, window.location.href);
        target.searchParams.set("ajax", "detail");
        target.searchParams.set("id", id);
        fetch(target.toString(), { headers: { "X-Requested-With": "fetch", Accept: "application/json" } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.html) {
                    toast("Could not load this list. Try again", "danger");
                    return;
                }
                canvas.querySelector(".offcanvas-title").textContent = data.title || "Details";
                canvas.querySelector(".offcanvas-body").innerHTML = data.html;
                canvas.querySelectorAll(".offcanvas-body [data-client-table]").forEach(function (el) {
                    window.mountClientTable(el);
                });
                window.bootstrap.Offcanvas.getOrCreateInstance(canvas).show();
            })
            .catch(function () {
                toast("Could not load this list. Try again", "danger");
            });
    }

    window.openDetail = openDetail;

    document.addEventListener("click", function (event) {
        const button = event.target.closest("[data-detail-id]");
        if (!button) {
            return;
        }
        const url = button.getAttribute("data-detail-url") || window.location.href;
        openDetail(url, button.getAttribute("data-detail-id"));
    });

    function updatePlan() {
        const host = document.getElementById("plan-note");
        if (!host) {
            return;
        }
        const startDate = valueOf("start_date");
        const endDate = valueOf("end_date");
        const dailyStart = valueOf("daily_start");
        const dailyEnd = valueOf("daily_end");
        const chairs = parseInt(valueOf("chairs"), 10);
        const holder = document.querySelector("[data-slot-minutes]");
        const minutes = parseInt((holder && holder.getAttribute("data-slot-minutes")) || "0", 10);
        if (!startDate || !endDate || !dailyStart || !dailyEnd || !(chairs > 0) || !(minutes > 0)) {
            host.textContent = "";
            return;
        }
        const start = new Date(startDate + "T00:00:00");
        const end = new Date(endDate + "T00:00:00");
        if (end < start) {
            host.textContent = "The end date must be on or after the start date.";
            return;
        }
        const startMin = minutesOf(dailyStart);
        const endMin = minutesOf(dailyEnd);
        if (endMin <= startMin) {
            host.textContent = "The daily end must be after the daily start.";
            return;
        }
        let clocksPerDay = 0;
        for (let cursor = startMin; cursor + minutes <= endMin; cursor += minutes) {
            clocksPerDay += 1;
        }
        const days = Math.round((end - start) / 86400000) + 1;
        const clocks = clocksPerDay * days;
        const total = clocks * chairs;
        host.textContent = total < 1
            ? "No time slots fall in that range. Check the daily start and end."
            : "This will add " + total + " time slots. " + clocks + " clock times, " + chairs + " time slots each.";
    }

    function valueOf(id) {
        const field = document.getElementById(id);
        return field ? field.value : "";
    }

    function minutesOf(value) {
        const parts = value.split(":");
        return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
    }

    document.querySelectorAll("[data-live-check]").forEach(function (field) {
        let timer = 0;
        field.addEventListener("input", function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                const url = new URL(field.dataset.liveCheck, window.location.href);
                url.searchParams.set("ajax", field.dataset.liveAjax || "check");
                url.searchParams.set("value", field.value);
                fetch(url.toString(), { headers: { Accept: "application/json", "X-Requested-With": "fetch" } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        let note = field.parentElement.querySelector(".live-check");
                        if (!note) {
                            note = document.createElement("div");
                            note.className = "form-text live-check";
                            field.insertAdjacentElement("afterend", note);
                        }
                        note.textContent = data.message || "";
                        note.classList.toggle("text-danger", data.ok === false);
                    });
            }, 300);
        });
    });

    window.bindAdminSuggest = function (options) {
        const form = document.getElementById("admin-search-form");
        const input = document.getElementById("admin-q");
        const box = document.getElementById("admin-results");
        if (!form || !input || !box) {
            return;
        }
        const roles = { ta: "TA", teacher: "Teacher", prof: "Professor" };
        const boot = options || {};
        let timer = 0;
        let rows = [];
        let active = -1;
        let requestId = 0;

        function closeList() {
            rows = [];
            active = -1;
            box.replaceChildren();
            box.hidden = true;
            form.classList.remove("is-open");
            input.setAttribute("aria-expanded", "false");
            input.removeAttribute("aria-activedescendant");
        }

        function markActive() {
            box.querySelectorAll(".admin-suggest-option").forEach(function (option, index) {
                const on = index === active;
                option.classList.toggle("is-active", on);
                option.setAttribute("aria-selected", on ? "true" : "false");
                if (on) {
                    input.setAttribute("aria-activedescendant", option.id);
                    option.scrollIntoView({ block: "nearest" });
                }
            });
            if (active < 0) {
                input.removeAttribute("aria-activedescendant");
            }
        }

        function pick(username) {
            if (!username) {
                return;
            }
            const grant = document.createElement("form");
            grant.method = "post";
            grant.className = "js-form";
            grant.setAttribute("action", window.location.pathname + window.location.search);
            grant.innerHTML = boot.csrf || "";
            const hidden = document.createElement("input");
            hidden.type = "hidden";
            hidden.name = "username";
            hidden.value = username;
            grant.appendChild(hidden);
            document.body.appendChild(grant);
            grant.requestSubmit();
        }

        function showEmpty(data) {
            box.replaceChildren();
            const note = document.createElement("p");
            note.className = "admin-suggest-empty";
            note.setAttribute("role", "status");
            note.textContent = (data && data.empty) || "No other active admin has that name.";
            if (data && data.canCreate) {
                note.appendChild(document.createTextNode(" Create one on the "));
                const link = document.createElement("a");
                link.href = data.adminsUrl || "admins.php";
                link.textContent = "Admins";
                note.appendChild(link);
                note.appendChild(document.createTextNode(" page."));
            }
            box.appendChild(note);
            input.removeAttribute("aria-activedescendant");
        }

        function showRows() {
            box.replaceChildren();
            rows.forEach(function (row, index) {
                const option = document.createElement("button");
                option.type = "button";
                option.className = "admin-suggest-option";
                option.id = "admin-opt-" + index;
                option.setAttribute("role", "option");
                option.setAttribute("aria-selected", "false");
                const name = document.createElement("span");
                name.className = "suggest-name";
                name.textContent = row.display_name || row.username;
                const meta = document.createElement("span");
                meta.className = "suggest-meta";
                const role = roles[row.role] || row.role || "";
                meta.textContent = role === "" ? row.username : row.username + " · " + role;
                option.appendChild(name);
                option.appendChild(document.createTextNode(" "));
                option.appendChild(meta);
                option.addEventListener("mouseenter", function () {
                    active = index;
                    markActive();
                });
                option.addEventListener("click", function () {
                    pick(row.username);
                });
                box.appendChild(option);
            });
            markActive();
        }

        function openList() {
            box.hidden = false;
            form.classList.add("is-open");
            input.setAttribute("aria-expanded", "true");
        }

        function runSearch() {
            const term = input.value.trim();
            const ticket = ++requestId;
            if (term === "") {
                closeList();
                return;
            }
            const url = new URL(window.location.href);
            url.searchParams.set("ajax", "admins");
            url.searchParams.set("q", term);
            fetch(url.toString(), { headers: { Accept: "application/json", "X-Requested-With": "fetch" } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (ticket !== requestId || input.value.trim() !== term) {
                        return;
                    }
                    rows = (data && data.rows) || [];
                    active = rows.length > 0 ? 0 : -1;
                    openList();
                    if (rows.length === 0) {
                        showEmpty(data);
                        return;
                    }
                    showRows();
                })
                .catch(function () {
                    if (ticket !== requestId) {
                        return;
                    }
                    closeList();
                    toast("Could not load this list. Try again", "danger");
                });
        }

        form.addEventListener("submit", function (event) {
            event.preventDefault();
        });
        input.addEventListener("input", function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(runSearch, 200);
        });
        input.addEventListener("keydown", function (event) {
            if (event.key === "ArrowDown" && rows.length > 0 && !box.hidden) {
                event.preventDefault();
                active = Math.min(rows.length - 1, active + 1);
                markActive();
            } else if (event.key === "ArrowUp" && rows.length > 0 && !box.hidden) {
                event.preventDefault();
                active = Math.max(0, active - 1);
                markActive();
            } else if (event.key === "Enter") {
                event.preventDefault();
                if (!box.hidden && rows.length > 0) {
                    pick(rows[active >= 0 ? active : 0].username);
                }
            } else if (event.key === "Escape") {
                closeList();
            }
        });
        document.addEventListener("click", function (event) {
            if (!form.contains(event.target)) {
                closeList();
            }
        });
    };

    document.querySelectorAll("form").forEach(syncMatch);
    updatePlan();
    document.querySelectorAll("[data-client-table]").forEach(function (el) {
        window.mountClientTable(el);
    });
    bindSlotRemoval();
    bindFileDrop();
})();
