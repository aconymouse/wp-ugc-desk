(function (wp) {
  var el = wp.element.createElement;
  var useSelect = wp.data.useSelect;
  var useDispatch = wp.data.useDispatch;
  var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
  var CheckboxControl = wp.components.CheckboxControl;
  var Notice = wp.components.Notice;

  if (!Panel) {
    return;
  }

  function angka(n) {
    return Number(n || 0).toLocaleString("id-ID");
  }

  function Notifikasi() {
    var data = useSelect(function (select) {
      var editor = select("core/editor");
      return {
        tipe: editor.getCurrentPostType(),
        status: editor.getCurrentPostAttribute("status"),
        meta: editor.getEditedPostAttribute("meta") || {},
        info: editor.getCurrentPostAttribute("ugcd_push_info") || {},
      };
    }, []);
    var editPost = useDispatch("core/editor").editPost;

    if (data.tipe !== "post") {
      return null;
    }

    var judul = { name: "ugcd-notifikasi", title: "Notifikasi", className: "ugcd-notifikasi" };
    var terkirim = data.info.terkirim;

    if (terkirim) {
      return el(
        Panel,
        judul,
        el("p", { style: { margin: 0 } }, "Terkirim ke " + angka(terkirim.target) + " pelanggan."),
        el(
          "p",
          { style: { margin: "6px 0 0", fontSize: "11px", color: "#757575" } },
          terkirim.waktu + (terkirim.oleh ? ", " + terkirim.oleh : "")
        )
      );
    }

    if (data.status === "private") {
      return el(Panel, judul, el("p", { style: { margin: 0, color: "#757575" } }, "Tulisan pribadi gak dikirim ke pembaca."));
    }

    var anak = [
      el(CheckboxControl, {
        key: "centang",
        label: "Kirim notifikasi ke pembaca",
        checked: Boolean(data.meta.ugcd_push),
        help: data.status === "publish" ? "Dikirim saat kamu klik Perbarui." : "Dikirim saat tulisan terbit.",
        onChange: function (nilai) {
          editPost({ meta: { ugcd_push: nilai } });
        },
        __nextHasNoMarginBottom: true,
      }),
    ];

    if (data.info.gagal && data.meta.ugcd_push) {
      anak.push(
        el(
          Notice,
          { key: "gagal", status: "error", isDismissible: false, className: "ugcd-notifikasi-gagal" },
          data.info.gagal + " Simpan ulang untuk mencoba lagi."
        )
      );
    }

    return el(Panel, judul, anak);
  }

  wp.plugins.registerPlugin("ugcd-notifikasi", { render: Notifikasi, icon: null });
})(window.wp);
