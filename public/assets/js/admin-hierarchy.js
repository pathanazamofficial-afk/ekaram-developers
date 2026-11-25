document.querySelectorAll(".admin-employee-hierarchy-form").forEach((form) => {
  const roleSelect = form.querySelector("[data-hierarchy-role]");
  const managerSelect = form.querySelector("[data-hierarchy-manager]");
  if (!roleSelect || !managerSelect) return;
  const hierarchyMessage = form.querySelector("[data-hierarchy-message]");

  const filterManagers = () => {
    const employeeLevel = Number(roleSelect.selectedOptions[0]?.dataset.level ?? 0);
    const requiredManagerLevel = employeeLevel === 5 ? 0 : employeeLevel + 1;
    let hasEligibleManager = false;
    Array.from(managerSelect.options).forEach((option) => {
      const visible = Number(option.dataset.level ?? -1) === requiredManagerLevel;
      option.hidden = !visible;
      option.disabled = !visible;
      if (visible && option.value) hasEligibleManager = true;
    });
    managerSelect.disabled = employeeLevel === 0;
    managerSelect.required = employeeLevel > 0 && employeeLevel < 5;
    if (managerSelect.selectedOptions.length && managerSelect.selectedOptions[0].disabled) {
      managerSelect.value = "";
    }
    if (hierarchyMessage) {
      if (employeeLevel === 0) {
        hierarchyMessage.textContent = "Choose a designation to see its eligible reporting manager.";
      } else if (employeeLevel === 5) {
        hierarchyMessage.textContent = "Deputy Director is the top level and does not have a reporting manager.";
      } else if (!hasEligibleManager) {
        const requiredDesignation = Array.from(roleSelect.options).find(
          (option) => Number(option.dataset.level ?? -1) === requiredManagerLevel
        )?.dataset.label;
        hierarchyMessage.textContent = `No active, approved ${requiredDesignation ?? "associate"} is available yet. Assign and approve one first.`;
      } else {
        hierarchyMessage.textContent = "Select an active, approved associate exactly one designation above.";
      }
    }
  };

  roleSelect.addEventListener("change", filterManagers);
  filterManagers();
});
