function ubahTema(tema) {
  document.documentElement.setAttribute("data-bs-theme", tema);
  localStorage.setItem("wecare-theme", tema);
  tampilkanTema();
}

function tampilkanTema() {
  const tema = document.documentElement.getAttribute("data-bs-theme");
  const tombol = document.querySelectorAll("[data-theme-toggle]");

  tombol.forEach(function (button) {
    const icon = button.querySelector("i");
    const label = button.querySelector("span");

    if (tema === "dark") {
      icon.className = "bi bi-sun me-2";
      label.textContent = "Light Mode";
    } else {
      icon.className = "bi bi-moon-stars me-2";
      label.textContent = "Dark Mode";
    }

    button.setAttribute("aria-label", "Ganti ke " + label.textContent);
  });
}

document.addEventListener("DOMContentLoaded", function () {
  tampilkanTema();

  document.querySelectorAll("[data-theme-toggle]").forEach(function (button) {
    button.addEventListener("click", function () {
      const tema = document.documentElement.getAttribute("data-bs-theme");

      if (tema === "dark") {
        ubahTema("light");
      } else {
        ubahTema("dark");
      }
    });
  });
});
