document.querySelectorAll("form[action='/api/marketing-associate/requests.php']").forEach((form) => {
  const projectSelect = form.querySelector("[data-request-project]");
  const plotSelect = form.querySelector("[data-request-plot]");
  if (!projectSelect || !plotSelect) return;

  const filterPlots = () => {
    let firstAvailable = null;
    Array.from(plotSelect.options).forEach((option) => {
      const visible = option.dataset.projectId === projectSelect.value;
      option.hidden = !visible;
      option.disabled = !visible;
      if (visible && firstAvailable === null) firstAvailable = option;
    });
    if (plotSelect.selectedOptions.length === 0 || plotSelect.selectedOptions[0].disabled) {
      if (firstAvailable) firstAvailable.selected = true;
    }
  };

  projectSelect.addEventListener("change", filterPlots);
  filterPlots();
});
