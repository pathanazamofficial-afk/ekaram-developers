const navToggle = document.querySelector(".nav-toggle");
const nav = document.querySelector(".primary-nav");

navToggle?.addEventListener("click", () => {
  const open = nav.classList.toggle("open");
  navToggle.setAttribute("aria-expanded", String(open));
});

nav?.querySelectorAll("a").forEach((link) => {
  link.addEventListener("click", () => {
    nav.classList.remove("open");
    navToggle?.setAttribute("aria-expanded", "false");
  });
});

const plotBoard = document.querySelector("[data-plot-board]");
const plotDialog = document.querySelector("[data-plot-dialog]");

const plotCards = plotBoard ? [...plotBoard.querySelectorAll(".plot")] : [];
const plotSearch = document.querySelector("[data-plot-search]");
const facingFilter = document.querySelector("[data-facing-filter]");
const statusFilter = document.querySelector("[data-status-filter]");
const plotFilterCount = document.querySelector("[data-plot-filter-count]");
const plotFilterEmpty = document.querySelector("[data-plot-filter-empty]");

function filterPlotCards() {
  const query = plotSearch?.value.trim().toLocaleLowerCase() || "";
  const facing = facingFilter?.value || "";
  const status = statusFilter?.value || "";
  const cardinalDirections = ["North", "South", "East", "West"];
  let visibleCount = 0;

  plotCards.forEach((plot) => {
    const facingParts = plot.dataset.facing.split(/\s*(?:&|-)\s*/);
    const facingMatches = !facing
      || (cardinalDirections.includes(facing) ? facingParts.includes(facing) : plot.dataset.facing === facing);
    const matches = facingMatches
      && (!status || plot.dataset.status === status)
      && (!query || plot.dataset.search.toLocaleLowerCase().includes(query));
    plot.hidden = !matches;
    if (matches) visibleCount += 1;
  });

  if (plotFilterCount) plotFilterCount.textContent = `${visibleCount} of ${plotCards.length} plots`;
  if (plotFilterEmpty) plotFilterEmpty.hidden = visibleCount !== 0;
}

plotSearch?.addEventListener("input", filterPlotCards);
facingFilter?.addEventListener("change", filterPlotCards);
statusFilter?.addEventListener("change", filterPlotCards);
document.querySelector("[data-plot-filter-reset]")?.addEventListener("click", () => {
  if (plotSearch) plotSearch.value = "";
  if (facingFilter) facingFilter.value = "";
  if (statusFilter) statusFilter.value = "";
  filterPlotCards();
});

plotBoard?.addEventListener("click", (event) => {
  const plot = event.target.closest(".plot");
  if (!plot || !plotDialog) return;

  plotDialog.querySelector("[data-plot-dialog-title]").textContent = `Plot ${plot.dataset.plot}`;
  plotDialog.querySelector("[data-plot-dialog-status]").textContent = plot.dataset.statusLabel;
  plotDialog.querySelector("[data-plot-dialog-area]").textContent = plot.dataset.area;
  plotDialog.querySelector("[data-plot-dialog-facing]").textContent = plot.dataset.facingLabel;
  plotDialog.querySelector("[data-plot-dialog-rate]").textContent = plot.dataset.rate;
  plotDialog.querySelector("[data-plot-dialog-price]").textContent = plot.dataset.price;
  plotDialog.querySelector("[data-enquire-plot]").dataset.enquirePlot = plot.dataset.plot;
  plotDialog.showModal();
});

document.querySelectorAll("[data-plot-zoom]").forEach((button) => {
  button.addEventListener("click", () => {
    const board = document.querySelector("[data-plot-board]");
    const output = document.querySelector("[data-plot-zoom-level]");
    if (!board || !output) return;

    const currentZoom = Number(board.dataset.zoom || "1");
    const nextZoom = button.dataset.plotZoom === "reset"
      ? 1
      : Math.min(1.5, Math.max(0.7, currentZoom + (button.dataset.plotZoom === "in" ? 0.1 : -0.1)));
    const roundedZoom = Math.round(nextZoom * 10) / 10;
    board.dataset.zoom = String(roundedZoom);
    board.style.zoom = String(roundedZoom);
    output.value = `${Math.round(roundedZoom * 100)}%`;
  });
});

document.querySelectorAll("[data-enquire-plot]").forEach((link) => {
  link.addEventListener("click", (event) => {
    const interest = document.querySelector("#interest");
    const message = document.querySelector("#message");
    if (interest) interest.value = "plot";
    if (message) message.value = `I would like details for Plot ${link.dataset.enquirePlot}.`;
    if (plotDialog?.open) {
      event.preventDefault();
      plotDialog.close();
      document.querySelector("#contact")?.scrollIntoView({ behavior: "smooth" });
    }
  });
});

document.querySelectorAll("#visit-date").forEach((input) => {
  const localDate = new Date();
  const offset = localDate.getTimezoneOffset() * 60000;
  input.min = new Date(localDate.getTime() - offset).toISOString().slice(0, 10);
});

document.querySelectorAll("form[data-lead-form]").forEach((form) => {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button[type='submit']");
    const status = form.querySelector(".form-status");
    if (!button || !status) return;

    button.disabled = true;
    status.textContent = "Submitting...";

    try {
      const response = await fetch(form.getAttribute("action"), {
        method: "POST",
        body: new FormData(form),
        headers: { Accept: "application/json" }
      });
      const result = await response.json();
      status.textContent = result.message || (response.ok
        ? "Your request has been received."
        : "The request could not be submitted.");
      if (response.ok && result.success) form.reset();
    } catch (error) {
      status.textContent = "The request could not be submitted. Please try again.";
    } finally {
      button.disabled = false;
    }
  });
});
