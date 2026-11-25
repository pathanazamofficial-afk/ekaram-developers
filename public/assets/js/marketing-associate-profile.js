document.querySelectorAll("[data-photo-input]").forEach((input) => {
  const preview = input.form?.querySelector("[data-photo-preview]");
  if (!preview) return;

  let objectUrl = null;
  const clearPreview = () => {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = null;
    preview.removeAttribute("src");
    preview.hidden = true;
  };

  input.addEventListener("change", () => {
    clearPreview();
    const file = input.files?.[0];
    if (!file) return;
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > 5 * 1024 * 1024) {
      input.setCustomValidity("Choose a JPG, PNG or WebP image smaller than 5 MB.");
      input.reportValidity();
      input.value = "";
      return;
    }

    input.setCustomValidity("");
    objectUrl = URL.createObjectURL(file);
    preview.src = objectUrl;
    preview.hidden = false;
  });

  input.form?.addEventListener("reset", clearPreview);
});

document.querySelectorAll("[data-position-control]").forEach((control) => {
  const form = control.form;
  const preview = form?.querySelector("[data-photo-position]");
  const updatePosition = () => {
  if (!form || !preview) return;
  const x = form.querySelector("[data-position-control='x']")?.value ?? "50";
  const y = form.querySelector("[data-position-control='y']")?.value ?? "50";
  preview.style.objectPosition = `${x}% ${y}%`;
  form.querySelectorAll("[data-position-value]").forEach((label) => {
    label.textContent = `${label.dataset.positionValue === "x" ? x : y}%`;
  });
  };
  control.addEventListener("input", updatePosition);
  updatePosition();
});
