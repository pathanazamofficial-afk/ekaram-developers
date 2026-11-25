document.querySelectorAll("form[data-sensitive-form]").forEach((form) => {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button[type='submit']");
    const output = form.querySelector("[data-sensitive-output]");
    if (!button || !output) return;

    button.disabled = true;
    output.textContent = "Retrieving sensitive details...";

    try {
      const response = await fetch(form.getAttribute("action"), {
        method: "POST",
        body: new FormData(form),
        headers: { Accept: "application/json" }
      });
      const result = await response.json();
      if (!response.ok || !result.success || !result.details) {
        output.textContent = result.message || "Sensitive details could not be retrieved.";
        return;
      }

      const labels = {
        aadhaar: "Aadhaar Number",
        pan: "PAN Number",
        account_number: "Bank Account Number",
        ifsc_code: "IFSC Code",
        branch_location: "Branch Location"
      };
      const list = document.createElement("dl");
      list.className = "sensitive-detail-list";
      Object.entries(labels).forEach(([key, label]) => {
        const row = document.createElement("div");
        const term = document.createElement("dt");
        const value = document.createElement("dd");
        term.textContent = label;
        value.textContent = String(result.details[key] || "");
        row.append(term, value);
        list.append(row);
      });
      output.replaceChildren(list);
    } catch (error) {
      output.textContent = "Sensitive details could not be retrieved. Please try again.";
    } finally {
      button.disabled = false;
    }
  });

  form.closest("details")?.addEventListener("toggle", (event) => {
    if (!event.currentTarget.open) {
      form.querySelector("[data-sensitive-output]")?.replaceChildren();
    }
  });
});
