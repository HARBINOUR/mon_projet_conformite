// SPDX-License-Identifier: MIT

/* global Chart */

/**
 * @typedef {Object} UploadResult
 * @property {number} total_csv
 * @property {number} total_found
 * @property {number} total_missing
 * @property {{NGAP:{found:number,missing:number},CCAM:{found:number,missing:number}}} by_type
 * @property {Array<string|number>} missing_acts_list
 */

(() => {
  "use strict";

  const APP_BASE_PATH =
    typeof window.APP_BASE_PATH === "string" ? window.APP_BASE_PATH : "";
  const EXPECTED_HEADER =
    "DATE_ACTE;NUM_INTERVENTION;NUM_VENUE;ACTE_ID;CODE_ACTE;ACTIVITE_OU_COEFF;TYPE_ACTE";
  const DEFAULT_FILE_LABEL = "Aucun fichier sélectionné";

  const fileInput = /** @type {HTMLInputElement|null} */ (
    document.getElementById("fileInput")
  );
  const fileName = document.getElementById("fileName");
  const dropzone = document.getElementById("dropzone");
  const btnCompare = /** @type {HTMLButtonElement|null} */ (
    document.getElementById("btnCompare")
  );
  const btnExport = /** @type {HTMLButtonElement|null} */ (
    document.getElementById("btnExport")
  );
  const overlay = document.getElementById("overlay");
  const alertsStack = document.getElementById("alerts");
  const kpiFound = document.getElementById("kpiFound");
  const kpiMissing = document.getElementById("kpiMissing");
  const kpiNgapMissing = document.getElementById("kpiNgapMissing");
  const kpiCcamMissing = document.getElementById("kpiCcamMissing");
  const pieCanvas = /** @type {HTMLCanvasElement|null} */ (
    document.getElementById("pieChart")
  );
  const currentYear = document.getElementById("currentYear");

  if (
    !fileInput ||
    !btnCompare ||
    !btnExport ||
    !dropzone ||
    !kpiFound ||
    !kpiMissing ||
    !kpiNgapMissing ||
    !kpiCcamMissing ||
    !overlay ||
    !pieCanvas ||
    !alertsStack
  ) {
    return;
  }

  if (currentYear) {
    currentYear.textContent = String(new Date().getFullYear());
  }

  let selectedFile = /** @type {File|null} */ (null);
  let currentResult = /** @type {UploadResult|null} */ (null);
  let lastValidatedToken = "";
  let chart = /** @type {Chart|null} */ (null);
  let pendingValidation = /** @type {Promise<boolean>|null} */ (null);

  if (fileName) {
    fileName.textContent = DEFAULT_FILE_LABEL;
  }
  btnExport.disabled = true;

  dropzone.addEventListener("dragenter", handleDragEnter);
  dropzone.addEventListener("dragover", handleDragEnter);
  dropzone.addEventListener("dragleave", handleDragLeave);
  dropzone.addEventListener("drop", handleDrop);

  fileInput.addEventListener("change", () => {
    const file =
      fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
    runValidation(file);
  });

  btnCompare.addEventListener("click", async () => {
    if (pendingValidation) {
      const stillValid = await pendingValidation;
      if (!stillValid) return;
    }

    if (!selectedFile) {
      showAlert({
        message: "Sélectionnez un fichier CSV avant de lancer la comparaison.",
        variant: "danger",
        focus: true,
      });
      fileInput.focus();
      return;
    }

    const token = getFileToken(selectedFile);
    if (!lastValidatedToken || token !== lastValidatedToken) {
      const ok = await runValidation(selectedFile);
      if (!ok) {
        return;
      }
    }

    await runCompare(selectedFile);
  });

  btnExport.addEventListener("click", () => {
    if (!currentResult) return;
    exportMissing(currentResult.missing_acts_list);
  });

  renderChart({ total_found: 0, total_missing: 0 });
  updateDetailedKpi();

  /**
   * @param {DragEvent} event
   */
  function handleDragEnter(event) {
    event.preventDefault();
    event.stopPropagation();
    event.dataTransfer && (event.dataTransfer.dropEffect = "copy");
    dropzone.classList.add("dragover");
  }

  /**
   * @param {DragEvent} event
   */
  function handleDragLeave(event) {
    event.preventDefault();
    event.stopPropagation();
    dropzone.classList.remove("dragover");
  }

  /**
   * @param {DragEvent} event
   */
  function handleDrop(event) {
    event.preventDefault();
    event.stopPropagation();
    dropzone.classList.remove("dragover");
    const file =
      event.dataTransfer && event.dataTransfer.files
        ? event.dataTransfer.files[0]
        : null;
    if (file && fileInput) {
      try {
        if (typeof DataTransfer === "function") {
          const transfer = new DataTransfer();
          transfer.items.add(file);
          fileInput.files = transfer.files;
        } else if (event.dataTransfer) {
          fileInput.files = event.dataTransfer.files;
        }
      } catch (_) {
        /* Certains navigateurs ne permettent pas de modifier fileInput.files. */
      }
    }
    runValidation(file);
  }

  /**
   * @param {File|null} file
   * @returns {Promise<boolean>}
   */
  function runValidation(file) {
    const validationPromise = handleFileSelection(file);
    pendingValidation = validationPromise;
    validationPromise.finally(() => {
      if (pendingValidation === validationPromise) {
        pendingValidation = null;
      }
    });
    return validationPromise;
  }

  /**
   * @param {File|null} file
   * @returns {Promise<boolean>}
   */
  async function handleFileSelection(file) {
    if (!file) {
      resetFileSelection();
      return false;
    }

    const token = getFileToken(file);
    const extensionValid = isCsvFile(file.name);
    if (!extensionValid) {
      dropzone.classList.add("error");
      resetFileSelection({ keepFileInputSelection: false });
      showAlert({
        message: "Seuls les fichiers .csv sont acceptés.",
        variant: "danger",
        focus: true,
      });
      return false;
    }

    const headerLine = await readHeaderLine(file);
    const normalizedHeader = headerLine.trim();
    if (!normalizedHeader) {
      dropzone.classList.add("error");
      resetFileSelection({ keepFileInputSelection: false });
      showAlert({
        message: `En-têtes invalides.\nTrouvées: (aucune ligne lisible)\nAttendu: ${EXPECTED_HEADER}`,
        variant: "danger",
        focus: true,
      });
      return false;
    }

    const comparableHeader = normalizeHeader(normalizedHeader);
    if (comparableHeader !== EXPECTED_HEADER) {
      dropzone.classList.add("error");
      resetFileSelection({ keepFileInputSelection: false });
      showAlert({
        message: `En-têtes invalides.\nTrouvées: ${
          comparableHeader || normalizedHeader
        }\nAttendu: ${EXPECTED_HEADER}`,
        variant: "danger",
        focus: true,
      });
      return false;
    }

    selectedFile = file;
    lastValidatedToken = token;
    dropzone.classList.remove("error");
    if (fileName) {
      fileName.textContent = file.name;
    }
    return true;
  }

  /**
   * @param {File} file
   * @returns {Promise<string>}
   */
  async function readHeaderLine(file) {
    const chunk = file.slice(0, 4096);
    const text = await chunk.text();
    const sanitized = text.replace(/\r\n?/g, "\n");
    const firstLine =
      sanitized.split("\n").find((line) => line.trim().length > 0) || "";
    return firstLine.replace(/^\uFEFF/, "");
  }

  /**
   * @param {string} path
   */
  function buildApiUrl(path) {
    const base = APP_BASE_PATH.replace(/\/$/, "");
    const normalized = path.startsWith("/") ? path : `/${path}`;
    return `${base}${normalized}` || normalized;
  }

  /**
   * @param {string} headerLine
   */
  function normalizeHeader(headerLine) {
    return headerLine
      .split(";")
      .map((field) => field.trim().toUpperCase())
      .filter((field) => field.length > 0)
      .join(";");
  }

  /**
   * @param {string} name
   */
  function isCsvFile(name) {
    return /\.csv$/i.test(name);
  }

  function resetFileSelection(options) {
    const { keepFileInputSelection = false } = options || {};
    selectedFile = null;
    lastValidatedToken = "";
    if (!keepFileInputSelection && fileInput) {
      fileInput.value = "";
    }
    dropzone.classList.remove("error");
    dropzone.classList.remove("dragover");
    if (fileName) {
      fileName.textContent = DEFAULT_FILE_LABEL;
    }
  }

  /**
   * @param {{message:string,variant?:"primary"|"secondary"|"success"|"danger"|"warning"|"info"|"light"|"dark",focus?:boolean,autoDismiss?:boolean}} config
   */
  function showAlert(config) {
    if (!alertsStack) return;
    const { message, variant = "danger", focus = false, autoDismiss } = config;
    const alert = document.createElement("div");
    alert.className = `alert alert-${variant} alert-dismissible fade show`;
    alert.setAttribute("role", "alert");
    alert.setAttribute("tabindex", "-1");

    const lines = message.split("\n");
    lines.forEach((line, index) => {
      const span = document.createElement("span");
      span.textContent = line;
      alert.appendChild(span);
      if (index < lines.length - 1) {
        alert.appendChild(document.createElement("br"));
      }
    });

    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "btn-close";
    closeBtn.setAttribute("aria-label", "Fermer");
    closeBtn.addEventListener("click", () => {
      alert.classList.remove("show");
      window.setTimeout(() => alert.remove(), 150);
    });
    alert.appendChild(closeBtn);

    alertsStack.prepend(alert);
    alert.scrollIntoView({ block: "nearest" });

    if (focus) {
      alert.focus({ preventScroll: true });
    }

    if (autoDismiss ?? variant !== "danger") {
      window.setTimeout(() => {
        if (alert.isConnected) {
          alert.classList.remove("show");
          window.setTimeout(() => alert.remove(), 150);
        }
      }, 8000);
    }
  }

  /**
   * @param {File} file
   */
  async function runCompare(file) {
    setBusy(true);
    try {
      const form = new FormData();
      form.append("file", file);

      const response = await fetch(buildApiUrl("api/upload"), {
        method: "POST",
        body: form,
      });
      let payload;
      try {
        payload = await response.json();
      } catch (parseError) {
        throw new Error("Réponse du serveur illisible.");
      }

      if (!response.ok) {
        const message =
          payload && payload.error && payload.error.message
            ? payload.error.message
            : `Erreur API (${response.status})`;
        throw new Error(message);
      }

      if (!payload || typeof payload.result !== "object") {
        throw new Error(
          "La réponse de l'API est invalide ou ne contient pas de résultats."
        );
      }

      // Les données de comparaison sont dans la clé "result"
      currentResult = payload.result;
      lastValidatedToken = getFileToken(file);

      updateKpi(currentResult);
      renderChart(currentResult);
      btnExport.disabled = !currentResult || currentResult.total_missing === 0;

      showAlert({
        message:
          "Comparaison terminée.\nLes indicateurs ont été mis à jour avec les dernières données.",
        variant: "success",
        autoDismiss: true,
      });
    } catch (error) {
      const message =
        error instanceof Error ? error.message : "Erreur inconnue";
      showAlert({
        message,
        variant: "danger",
        focus: true,
      });
    } finally {
      setBusy(false);
    }
  }

  /**
   * @param {UploadResult} data
   */
  function updateKpi(data) {
    kpiFound.textContent = String(data.total_found);
    kpiMissing.textContent = String(data.total_missing);
    updateDetailedKpi(data.by_type); // Appel pour mettre à jour les détails NGAP/CCAM
  }

  /**
   * @param {{total_found:number,total_missing:number}} data
   */
  function renderChart(data) {
    if (!pieCanvas) return;

    const found = Number.isFinite(data.total_found) ? data.total_found : 0;
    const missing = Number.isFinite(data.total_missing)
      ? data.total_missing
      : 0;
    const total = found + missing;
    const datasetValues = total === 0 ? [1, 1] : [missing, found];

    const colors = ["#dc3545", "#198754"];
    const hoverColors = ["#b02a37", "#146c43"];

    if (chart) {
      chart.destroy();
    }

    chart = new Chart(pieCanvas, {
      type: "pie",
      data: {
        labels: ["Actes non trouvés", "Actes trouvés"],
        datasets: [
          {
            data: datasetValues,
            backgroundColor: colors,
            hoverBackgroundColor: hoverColors,
            borderWidth: 2,
            borderColor: "#ffffff",
          },
        ],
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            position: "bottom",
            labels: {
              usePointStyle: true,
              color: "#1f2937",
            },
          },
          tooltip: {
            callbacks: {
              label: (context) => {
                const index = context.dataIndex;
                const actualValue = index === 0 ? missing : found;
                const pct = total === 0 ? 0 : (actualValue * 100) / total;
                return `${context.label}: ${actualValue} (${pct.toFixed(1)}%)`;
              },
            },
          },
        },
      },
    });
  }

  /**
   * @param {{NGAP?:{missing?:number},CCAM?:{missing?:number}}} [byType]
   */
  function updateDetailedKpi(byType) {
    const ngapMissing = Number(byType?.NGAP?.missing ?? 0);
    const ccamMissing = Number(byType?.CCAM?.missing ?? 0);
    kpiNgapMissing.textContent = String(ngapMissing);
    kpiCcamMissing.textContent = String(ccamMissing);
  }

  /**
   * @param {Array<{id:string|number, type:string, reason:string}>} missingActs
   */
  function exportMissing(missingActs) {
    if (!missingActs || missingActs.length === 0) return;
    const now = new Date();
    const pad = (value) => String(value).padStart(2, "0");
    const stamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(
      now.getDate()
    )}_${pad(now.getHours())}${pad(now.getMinutes())}`;
    const name = `Actes_Manquants_${stamp}.csv`;
    const header = "acte_id;type_acte;raison_discordance\n";
    const body = missingActs
      .map((act) => `${act.id};${act.type};${act.reason || ""}`)
      .join("\n");
    const blob = new Blob([header + body], { type: "text/csv;charset=utf-8" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  /**
   * @param {boolean} busy
   */
  function setBusy(busy) {
    btnCompare.disabled = busy;
    btnExport.disabled =
      busy || !currentResult || currentResult.total_missing === 0;
    fileInput.disabled = busy;
    dropzone.classList.toggle("disabled", busy);
    overlay.classList.toggle("d-none", !busy);
    overlay.setAttribute("aria-hidden", String(!busy));
  }

  /**
   * @param {File} file
   */
  function getFileToken(file) {
    return `${file.name}::${file.size}::${file.lastModified}`;
  }
})();

(() => {
  const TEXT = "© 2025 Harbi Noureddine";
  const LEGAL_URL = (window.APP_BASE_PATH || "") + "/legal.html";
  const LEGAL_LABEL = "Mentions légales";
  const INLINE_STYLE =
    "position:fixed;bottom:0;left:0;right:0;text-align:center;font:12px/1.45 system-ui;background:#f6f6f6;color:#333;padding:6px 12px;z-index:2147483647;user-select:none;box-shadow:0 -2px 8px rgba(15,23,42,0.08);pointer-events:auto;";

  let docObserver = null;
  let bodyObserver = null;
  let shadowHost = null;
  let integrityTimer = null;
  let previousFilter = null;

  const isFooterCompliant = (footer) =>
    Boolean(
      footer &&
        footer.dataset.legalSignature === TEXT &&
        footer.querySelector("a")?.textContent === LEGAL_LABEL &&
        footer.querySelector("a")?.getAttribute("href") === LEGAL_URL
    );

  const buildFooter = (footer) => {
    footer.classList.add("legal-footer");
    footer.setAttribute("role", "contentinfo");
    footer.setAttribute("aria-label", `${TEXT} — ${LEGAL_LABEL}`);
    footer.dataset.legalSignature = TEXT;
    footer.style.cssText = INLINE_STYLE;

    while (footer.firstChild) {
      footer.removeChild(footer.firstChild);
    }

    footer.appendChild(document.createTextNode(TEXT));

    const spacer = document.createTextNode(" ");
    footer.appendChild(spacer);

    const linksContainer = document.createElement("span");
    linksContainer.className = "legal-footer-links";

    const separator = document.createElement("span");
    separator.setAttribute("aria-hidden", "true");
    separator.textContent = "—";
    linksContainer.appendChild(separator);

    const link = document.createElement("a");
    link.className = "legal-footer-link";
    link.href = LEGAL_URL;
    link.textContent = LEGAL_LABEL;
    link.rel = "nofollow noopener";
    linksContainer.appendChild(link);

    footer.appendChild(linksContainer);
  };

  const ensureFooter = () => {
    let footer = document.getElementById("copyright");
    if (!footer) {
      footer = document.createElement("footer");
      footer.id = "copyright";
      document.body.appendChild(footer);
    }

    if (!isFooterCompliant(footer)) {
      buildFooter(footer);
    }
  };

  const ensureShadowFooter = () => {
    if (shadowHost) return;
    shadowHost = document.createElement("div");
    shadowHost.id = "legal-footer-host";
    document.body.appendChild(shadowHost);

    const shadowRoot = shadowHost.attachShadow({ mode: "closed" });
    const wrapper = document.createElement("div");
    wrapper.textContent = TEXT;
    const style = document.createElement("style");
    style.textContent =
      ":host{all:initial} div{position:fixed;bottom:0;left:0;right:0;text-align:center;font:12px/1.4 system-ui;background:#f6f6f6;color:#333;padding:6px 12px;z-index:2147483646;pointer-events:none;user-select:none;}";
    shadowRoot.appendChild(style);
    shadowRoot.appendChild(wrapper);

    bodyObserver = new MutationObserver(() => {
      if (shadowHost && document.body && !document.body.contains(shadowHost)) {
        document.body.appendChild(shadowHost);
      }
    });
    bodyObserver.observe(document.body, { childList: true });
  };

  const startObservers = () => {
    if (docObserver) return;
    docObserver = new MutationObserver(() => ensureFooter());
    docObserver.observe(document.documentElement, {
      childList: true,
      subtree: true,
      characterData: true,
    });
  };

  const startIntegrityCheck = () => {
    const check = () => {
      const footer = document.getElementById("copyright");
      if (!isFooterCompliant(footer)) {
        console.error("Footer légal manquant ou altéré.");
        if (previousFilter === null) {
          previousFilter = document.documentElement.style.filter;
        }
        document.documentElement.style.filter = "grayscale(1)";
        ensureFooter();
      } else {
        if (previousFilter !== null) {
          document.documentElement.style.filter = previousFilter;
          previousFilter = null;
        }
      }
    };

    if (!integrityTimer) {
      integrityTimer = window.setInterval(check, 3000);
    }
    check();
  };

  const bootstrap = () => {
    if (!document.body) {
      window.setTimeout(bootstrap, 50);
      return;
    }
    ensureFooter();
    ensureShadowFooter();
    startObservers();
    startIntegrityCheck();
  };

  if (document.readyState === "loading") {
    window.addEventListener("DOMContentLoaded", bootstrap, { once: true });
  } else {
    bootstrap();
  }
})();
