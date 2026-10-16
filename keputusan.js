(function (wp) {
  var el = wp.element.createElement;
  var useSelect = wp.data.useSelect;
  var useDispatch = wp.data.useDispatch;
  var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
  var SelectControl = wp.components.SelectControl;
  var TextareaControl = wp.components.TextareaControl;
  var Notice = wp.components.Notice;
  var Button = wp.components.Button;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var apiFetch = wp.apiFetch;
  var bolehLihat = Boolean(window.ugcdKeputusan && window.ugcdKeputusan.lihat);

  if (!Panel) {
    return;
  }

  function KeputusanRedaksi() {
    var data = useSelect(function (select) {
      var editor = select("core/editor");
      return {
        meta: editor.getEditedPostAttribute("meta") || {},
        status: editor.getEditedPostAttribute("status"),
        tipe: editor.getCurrentPostType(),
        id: editor.getCurrentPostId(),
        tersimpan: editor.getCurrentPostAttribute("modified"),
      };
    }, []);
    var editPost = useDispatch("core/editor").editPost;
    var penanda = useState(null);
    var pemutus = penanda[0];
    var setPemutus = penanda[1];

    useEffect(
      function () {
        if (!bolehLihat || !data.id || !data.meta.ugcd_naskah_kontributor) return;
        var aktif = true;
        apiFetch({ path: "/ugc-desk/v1/keputusan/" + data.id })
          .then(function (hasil) {
            if (aktif) setPemutus(hasil && hasil.oleh ? hasil : null);
          })
          .catch(function () {
            if (aktif) setPemutus(null);
          });
        return function () {
          aktif = false;
        };
      },
      [data.id, data.tersimpan, data.meta.ugcd_naskah_kontributor]
    );

    if (data.tipe !== "post" || !data.meta.ugcd_naskah_kontributor) {
      return null;
    }

    var keputusan = data.meta.ugcd_keputusan || "";
    var alasan = data.meta.ugcd_alasan || "";

    function ubah(bagian) {
      editPost({ meta: bagian });
    }

    var anak = [];

    if (data.meta.ugcd_ubah_status === "baru") {
      anak.push(
        el(
          Notice,
          { key: "ubah", status: "warning", isDismissible: false },
          el("strong", null, "Penulis minta perubahan"),
          el("p", { style: { margin: "6px 0 10px", whiteSpace: "pre-wrap" } }, data.meta.ugcd_ubah_pesan || ""),
          el(
            Button,
            {
              variant: "secondary",
              size: "small",
              onClick: function () {
                ubah({ ugcd_ubah_status: "" });
              },
            },
            "Tandai selesai"
          )
        )
      );
    }

    if (data.status === "publish" || data.status === "private") {
      if (!anak.length) {
        anak.push(el("p", { key: "terbit", style: { margin: 0, color: "#757575" } }, "Tulisan ini sudah terbit."));
      }
      return el(Panel, { name: "ugcd-keputusan", title: "Keputusan redaksi", className: "ugcd-keputusan" }, anak);
    }

    anak.push(
      el(SelectControl, {
        key: "keputusan",
        label: "Keputusan",
        value: keputusan,
        options: [
          { label: "—", value: "" },
          { label: "Perlu revisi", value: "revisi" },
          { label: "Ditolak", value: "ditolak" },
        ],
        onChange: function (nilai) {
          ubah({ ugcd_keputusan: nilai });
        },
        __nextHasNoMarginBottom: true,
        __next40pxDefaultSize: true,
      })
    );

    if (keputusan) {
      anak.push(
        el(TextareaControl, {
          key: "alasan",
          label: "Catatan untuk penulis",
          value: alasan,
          rows: 5,
          onChange: function (nilai) {
            ubah({ ugcd_alasan: nilai });
          },
          __nextHasNoMarginBottom: true,
        })
      );
      anak.push(
        el(
          Notice,
          { key: "info", status: "info", isDismissible: false },
          keputusan === "revisi"
            ? "Setelah disimpan, penulis bisa memperbaiki naskah ini dari akunnya."
            : "Setelah disimpan, naskah ditandai ditolak di akun penulis."
        )
      );
    }

    if (pemutus) {
      anak.push(
        el(
          "p",
          { key: "oleh", style: { margin: "10px 0 0", fontSize: "11px", color: "#757575" } },
          "Keputusan terakhir: " + pemutus.oleh + (pemutus.waktu ? ", " + pemutus.waktu : "")
        )
      );
    }

    return el(Panel, { name: "ugcd-keputusan", title: "Keputusan redaksi", className: "ugcd-keputusan" }, anak);
  }

  wp.plugins.registerPlugin("ugcd-keputusan", { render: KeputusanRedaksi, icon: null });
})(window.wp);
