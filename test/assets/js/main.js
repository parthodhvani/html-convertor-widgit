// Petra Müller — main.js
(function () {
  "use strict";

  // Mobile nav toggle
  const toggle = document.querySelector(".menu-toggle");
  const nav = document.querySelector(".nav");
  if (toggle && nav) {
    toggle.addEventListener("click", () => {
      nav.classList.toggle("open");
      toggle.setAttribute("aria-expanded", nav.classList.contains("open"));
    });
  }

  // Active nav link
  const path = location.pathname.split("/").pop() || "index.html";
  document.querySelectorAll(".nav-list a").forEach((a) => {
    if (a.getAttribute("href") === path) a.classList.add("active");
  });

  // FAQ accordion
  document.querySelectorAll(".faq-item").forEach((item) => {
    const q = item.querySelector(".faq-q");
    if (!q) return;
    q.addEventListener("click", () => {
      const isOpen = item.classList.contains("open");
      // close siblings
      item.parentElement.querySelectorAll(".faq-item.open").forEach((el) => el.classList.remove("open"));
      if (!isOpen) item.classList.add("open");
    });
  });

  // Reveal on scroll
  const io = new IntersectionObserver(
    (entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) {
          e.target.classList.add("in");
          io.unobserve(e.target);
        }
      });
    },
    { threshold: 0.12 }
  );
  document.querySelectorAll(".reveal").forEach((el) => io.observe(el));

  // Contact / newsletter form success
  document.querySelectorAll("form[data-form]").forEach((form) => {
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      const success = form.querySelector(".form-success");
      if (success) success.classList.add("show");
      form.reset();
      setTimeout(() => success && success.classList.remove("show"), 5000);
    });
  });

  // Category chips (blog)
  document.querySelectorAll(".chips").forEach((group) => {
    group.querySelectorAll(".chip").forEach((chip) => {
      chip.addEventListener("click", () => {
        group.querySelectorAll(".chip").forEach((c) => c.classList.remove("active"));
        chip.classList.add("active");
      });
    });
  });
})();
