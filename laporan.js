(function () {
  var HARI_MS = 86400000;
  var BULAN = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
  var BULAN_PENUH = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
  var HARI = ["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"];

  function dua(n) {
    return (n < 10 ? "0" : "") + n;
  }

  function urai(t) {
    var p = String(t).split("-");
    return Date.UTC(+p[0], +p[1] - 1, +p[2]);
  }

  function tulis(ms) {
    var d = new Date(ms);
    return d.getUTCFullYear() + "-" + dua(d.getUTCMonth() + 1) + "-" + dua(d.getUTCDate());
  }

  function pendek(ms, tahun) {
    var d = new Date(ms);
    return d.getUTCDate() + " " + BULAN[d.getUTCMonth()] + (tahun ? " " + d.getUTCFullYear() : "");
  }

  function label(a, b) {
    if (a === b) return pendek(a, true);
    var beda = new Date(a).getUTCFullYear() !== new Date(b).getUTCFullYear();
    return pendek(a, beda) + " – " + pendek(b, true);
  }

  function awalBulan(ms) {
    var d = new Date(ms);
    return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1);
  }

  function geserBulan(ms, n) {
    var d = new Date(ms);
    return Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1);
  }

  function akhirBulan(ms) {
    var d = new Date(ms);
    return Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0);
  }

  function tombol(teks) {
    var b = document.createElement("button");
    b.type = "button";
    b.textContent = teks;
    return b;
  }

  document.querySelectorAll(".ugcd-laporan form.alat").forEach(function (form) {
    var pilihan = form.querySelector('select[name="sumber"]');
    if (pilihan) {
      pilihan.addEventListener("change", function () {
        form.submit();
      });
    }

    var el = form.querySelector(".rentang");
    if (!el) return;
    var pemicu = el.querySelector(".rentang-tombol");
    var teksEl = el.querySelector(".rentang-teks");
    var inDari = el.querySelector('input[name="dari"]');
    var inSampai = el.querySelector('input[name="sampai"]');
    var hariIni = urai(el.dataset.hariIni);
    var mulai = urai(inDari.value);
    var akhir = urai(inSampai.value);
    var pilihMulai = null;
    var tampil = awalBulan(akhir);

    var panel = document.createElement("div");
    panel.className = "rentang-panel";
    panel.hidden = true;
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", "Pilih rentang tanggal");
    el.appendChild(panel);

    var preset = [
      ["Hari ini", function () { return [hariIni, hariIni]; }],
      ["Kemarin", function () { return [hariIni - HARI_MS, hariIni - HARI_MS]; }],
      ["7 hari", function () { return [hariIni - 6 * HARI_MS, hariIni]; }],
      ["30 hari", function () { return [hariIni - 29 * HARI_MS, hariIni]; }],
      ["Bulan ini", function () { return [awalBulan(hariIni), hariIni]; }],
      ["Bulan lalu", function () { var a = geserBulan(hariIni, -1); return [a, akhirBulan(a)]; }]
    ];

    function tutup() {
      panel.hidden = true;
      pemicu.setAttribute("aria-expanded", "false");
    }

    function pakai(a, b) {
      inDari.value = tulis(a);
      inSampai.value = tulis(b);
      teksEl.textContent = label(a, b);
      tutup();
      form.submit();
    }

    function gambar() {
      panel.textContent = "";

      var kiri = document.createElement("div");
      kiri.className = "rentang-preset";
      preset.forEach(function (p) {
        var b = tombol(p[0]);
        b.addEventListener("click", function () {
          var r = p[1]();
          pakai(r[0], r[1]);
        });
        kiri.appendChild(b);
      });

      var kanan = document.createElement("div");
      kanan.className = "rentang-kalender";

      var kepala = document.createElement("div");
      kepala.className = "rentang-kepala";
      var mundur = tombol("‹");
      mundur.setAttribute("aria-label", "Bulan sebelumnya");
      mundur.addEventListener("click", function () {
        tampil = geserBulan(tampil, -1);
        gambar();
      });
      var maju = tombol("›");
      maju.setAttribute("aria-label", "Bulan berikutnya");
      maju.disabled = geserBulan(tampil, 1) > hariIni;
      maju.addEventListener("click", function () {
        tampil = geserBulan(tampil, 1);
        gambar();
      });
      var judul = document.createElement("span");
      var d = new Date(tampil);
      judul.textContent = BULAN_PENUH[d.getUTCMonth()] + " " + d.getUTCFullYear();
      kepala.append(mundur, judul, maju);

      var grid = document.createElement("div");
      grid.className = "rentang-grid";
      HARI.forEach(function (h) {
        var s = document.createElement("span");
        s.textContent = h;
        grid.appendChild(s);
      });
      var geser = (new Date(tampil).getUTCDay() + 6) % 7;
      for (var i = 0; i < geser; i++) grid.appendChild(document.createElement("i"));

      var a = pilihMulai !== null ? pilihMulai : mulai;
      var z = pilihMulai !== null ? pilihMulai : akhir;
      var ujungBulan = akhirBulan(tampil);
      for (var t = tampil; t <= ujungBulan; t += HARI_MS) {
        (function (t) {
          var b = tombol(String(new Date(t).getUTCDate()));
          if (t === a || t === z) b.classList.add("ujung");
          else if (t > a && t < z) b.classList.add("di");
          if (t === hariIni) b.classList.add("kini");
          b.disabled = t > hariIni;
          b.addEventListener("click", function () {
            if (pilihMulai === null) {
              pilihMulai = t;
              gambar();
              return;
            }
            pakai(Math.min(pilihMulai, t), Math.max(pilihMulai, t));
          });
          grid.appendChild(b);
        })(t);
      }

      var kaki = document.createElement("div");
      kaki.className = "rentang-kaki";
      kaki.textContent = pilihMulai === null ? "Pilih tanggal awal" : "Pilih tanggal akhir";

      kanan.append(kepala, grid, kaki);
      panel.append(kiri, kanan);
    }

    pemicu.addEventListener("click", function () {
      if (!panel.hidden) {
        tutup();
        return;
      }
      pilihMulai = null;
      tampil = awalBulan(akhir);
      gambar();
      panel.hidden = false;
      pemicu.setAttribute("aria-expanded", "true");
    });

    document.addEventListener("mousedown", function (e) {
      if (!panel.hidden && !el.contains(e.target)) tutup();
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !panel.hidden) {
        tutup();
        pemicu.focus();
      }
    });
  });
})();
