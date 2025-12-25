"use strict";
!function () {
    var t = document.querySelector("#basic-alert"), e = document.querySelector("#with-title"),
        n = document.querySelector("#footer-alert"), o = document.querySelector("#html-alert"),
        i = document.querySelector("#position-top-start"), s = document.querySelector("#position-top-end"),
        a = document.querySelector("#position-bottom-start"), c = document.querySelector("#position-bottom-end"),
        l = document.querySelector("#bounce-in-animation"), r = document.querySelector("#fade-in-animation"),
        u = document.querySelector("#flip-x-animation"), m = document.querySelector("#tada-animation"),
        f = document.querySelector("#shake-animation"), w = document.querySelector("#type-success"),
        b = document.querySelector("#type-info"), h = document.querySelector("#type-warning"),
        d = document.querySelector("#type-error"), g = document.querySelector("#type-question"),
        S = document.querySelector("#custom-image"), y = document.querySelector("#auto-close"),
        p = document.querySelector("#outside-click"), v = document.querySelector("#progress-steps"),
        C = document.querySelector("#ajax-request"), B = document.querySelector("#confirm-text"),
        k = document.querySelector("#confirm-color");
    t && (t.onclick = function () {
        Swal.fire({
            title: "Any fool can use a computer",
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), e && (e.onclick = function () {
        Swal.fire({
            title: "The Internet?,",
            text: "That thing is still around?",
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), n && (n.onclick = function () {
        Swal.fire({
            icon: "error",
            title: "Oops...",
            text: "Something went wrong!",
            footer: "<a href>Why do I have this issue?</a>",
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), o && (o.onclick = function () {
        Swal.fire({
            title: '<span class="fw-medium">HTML <u>example</u></span>',
            icon: "info",
            html: 'You can use <b>bold text</b>, <a href="https://pixinvent.com/" target="_blank">links</a> and other HTML tags',
            showCloseButton: !0,
            showCancelButton: !0,
            focusConfirm: !1,
            confirmButtonText: '<i class="ti ti-thumb-up"></i> Great!',
            confirmButtonAriaLabel: "Thumbs up, great!",
            cancelButtonText: '<i class="ti ti-thumb-down"></i>',
            cancelButtonAriaLabel: "Thumbs down",
            customClass: {
                confirmButton: "btn btn-primary me-3 waves-effect waves-light",
                cancelButton: "btn btn-label-secondary waves-effect waves-light"
            },
            buttonsStyling: !1
        })
    }), i && (i.onclick = function () {
        Swal.fire({
            position: "top-start",
            icon: "success",
            title: "Your work has been saved",
            showConfirmButton: !1,
            timer: 1500,
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), s && (s.onclick = function () {
        Swal.fire({
            position: "top-end",
            icon: "success",
            title: "Your work has been saved",
            showConfirmButton: !1,
            timer: 1500,
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), a && (a.onclick = function () {
        Swal.fire({
            position: "bottom-start",
            icon: "success",
            title: "Your work has been saved",
            showConfirmButton: !1,
            timer: 1500,
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), c && (c.onclick = function () {
        Swal.fire({
            position: "bottom-end",
            icon: "success",
            title: "Your work has been saved",
            showConfirmButton: !1,
            timer: 1500,
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), l && (l.onclick = function () {
        Swal.fire({
            title: "Bounce In Animation",
            showClass: {popup: "animate__animated animate__bounceIn"},
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), r && (r.onclick = function () {
        Swal.fire({
            title: "Fade In Animation",
            showClass: {popup: "animate__animated animate__fadeIn"},
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), u && (u.onclick = function () {
        Swal.fire({
            title: "Flip In Animation",
            showClass: {popup: "animate__animated animate__flipInX"},
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), m && (m.onclick = function () {
        Swal.fire({
            title: "Tada Animation",
            showClass: {popup: "animate__animated animate__tada"},
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), f && (f.onclick = function () {
        Swal.fire({
            title: "Shake Animation",
            showClass: {popup: "animate__animated animate__shakeX"},
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), w && (w.onclick = function () {
        Swal.fire({
            title: "Good job!",
            text: "You clicked the button!",
            icon: "success",
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), b && (b.onclick = function () {
        Swal.fire({
            title: "Info!",
            text: "You clicked the button!",
            icon: "info",
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), h && (h.onclick = function () {
        Swal.fire({
            title: "Warning!",
            text: " You clicked the button!",
            icon: "warning",
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), d && (d.onclick = function () {
        Swal.fire({
            title: "Error!",
            text: " You clicked the button!",
            icon: "error",
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), g && (g.onclick = function () {
        Swal.fire({
            title: "Question!",
            text: " You clicked the button!",
            icon: "question",
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), S && (S.onclick = function () {
        Swal.fire({
            title: "Sweet!",
            text: "Modal with a custom image.",
            imageUrl: assetsPath + "img/backgrounds/5.jpg",
            imageWidth: 400,
            imageAlt: "Custom image",
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), y && (y.onclick = function () {
        var t;
        Swal.fire({
            title: "Auto close alert!",
            html: 'I will close in <span class="fw-medium"></span> seconds.',
            timer: 2e3,
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1,
            willOpen: function () {
                Swal.showLoading(), t = setInterval(function () {
                    Swal.getHtmlContainer().querySelector("strong").textContent = Swal.getTimerLeft()
                }, 100)
            },
            willClose: function () {
                clearInterval(t)
            }
        }).then(function (t) {
            t.dismiss === Swal.DismissReason.timer && console.log("I was closed by the timer")
        })
    }), p && (p.onclick = function () {
        Swal.fire({
            title: "Click outside to close!",
            text: "This is a cool message!",
            backdrop: !0,
            allowOutsideClick: !0,
            customClass: {confirmButton: "btn btn-primary waves-effect waves-light"},
            buttonsStyling: !1
        })
    }), v && (v.onclick = function () {
        const o = ["1", "2", "3"], i = Swal.mixin({
            confirmButtonText: "Forward",
            cancelButtonText: "Back",
            progressSteps: o,
            input: "text",
            inputAttributes: {required: !0},
            validationMessage: "This field is required"
        });
        !async function () {
            var t = [];
            let e;
            for (e = 0; e < o.length;) {
                var n = await new i({title: "Question " + o[e], showCancelButton: 0 < e, currentProgressStep: e});
                n.value ? (t[e] = n.value, e++) : "cancel" === n.dismiss && e--
            }
            Swal.fire(JSON.stringify(t))
        }()
    }), C && (C.onclick = function () {
        Swal.fire({
            title: "Submit your Github username",
            input: "text",
            inputAttributes: {autocapitalize: "off"},
            showCancelButton: !0,
            confirmButtonText: "Look up",
            showLoaderOnConfirm: !0,
            customClass: {
                confirmButton: "btn btn-primary me-3 waves-effect waves-light",
                cancelButton: "btn btn-label-danger waves-effect waves-light"
            },
            preConfirm: t => fetch("//api.github.com/users/" + t).then(t => {
                if (t.ok) return t.json();
                throw new Error(t.statusText)
            }).catch(t => {
                Swal.showValidationMessage("Request failed:" + t)
            }),
            backdrop: !0,
            allowOutsideClick: () => !Swal.isLoading()
        }).then(t => {
            t.isConfirmed && Swal.fire({
                title: t.value.login + "'s avatar",
                imageUrl: t.value.avatar_url,
                customClass: {confirmButtonText: "Close me!", confirmButton: "btn btn-primary waves-effect waves-light"}
            })
        })
    }), B && (B.onclick = function () {
        Swal.fire({
            title: "Are you sure?",
            text: "You won't be able to revert this!",
            icon: "warning",
            showCancelButton: !0,
            confirmButtonText: "Yes, delete it!",
            customClass: {
                confirmButton: "btn btn-primary me-3 waves-effect waves-light",
                cancelButton: "btn btn-label-secondary waves-effect waves-light"
            },
            buttonsStyling: !1
        }).then(function (t) {
            t.value && Swal.fire({
                icon: "success",
                title: "Deleted!",
                text: "Your file has been deleted.",
                customClass: {confirmButton: "btn btn-success waves-effect waves-light"}
            })
        })
    }), k && (k.onclick = function () {
        Swal.fire({
            title: "Are you sure?",
            text: "You won't be able to revert this!",
            icon: "warning",
            showCancelButton: !0,
            confirmButtonText: "Yes, delete it!",
            customClass: {
                confirmButton: "btn btn-primary me-3 waves-effect waves-light",
                cancelButton: "btn btn-label-secondary waves-effect waves-light"
            },
            buttonsStyling: !1
        }).then(function (t) {
            t.value ? Swal.fire({
                icon: "success",
                title: "Deleted!",
                text: "Your file has been deleted.",
                customClass: {confirmButton: "btn btn-success waves-effect waves-light"}
            }) : t.dismiss === Swal.DismissReason.cancel && Swal.fire({
                title: "Cancelled",
                text: "Your imaginary file is safe :)",
                icon: "error",
                customClass: {confirmButton: "btn btn-success waves-effect waves-light"}
            })
        })
    })
}();


/* Examples */
(function ($) {
    /*
     * Example 1:
     *
     * - no animation
     * - custom gradient
     *
     * By the way - you may specify more than 2 colors for the gradient
     */
    $('.first.circle').circleProgress({
        value: 0.35,
        animation: false,
        fill: {gradient: ['#ff1e41', '#ff5f43']}
    });

    /*
     * Example 2:
     *
     * - default gradient
     * - listening to `circle-animation-progress` event and display the animation progress: from 0 to 100%
     */
    $('.second.circle').circleProgress({
        value: 0.6
    }).on('circle-animation-progress', function (event, progress) {
        $(this).find('strong').html(Math.round(100 * progress) + '<i>%</i>');
    });

    /*
     * Example 3:
     *
     * - very custom gradient
     * - listening to `circle-animation-progress` event and display the dynamic change of the value: from 0 to 0.8
     */
    $('.third.circle').circleProgress({
        value: 0.75,
        fill: {gradient: [['#0681c4', .5], ['#4ac5f8', .5]], gradientAngle: Math.PI / 4}
    }).on('circle-animation-progress', function (event, progress, stepValue) {
        $(this).find('strong').text(stepValue.toFixed(2).substr(1));
    });

    /*
     * Example 4:
     *
     * - solid color fill
     * - custom start angle
     * - custom line cap
     * - dynamic value set
     */
    var c4 = $('.forth.circle');

    c4.circleProgress({
        startAngle: -Math.PI / 4 * 3,
        value: 0.5,
        lineCap: 'round',
        fill: {color: '#ffa500'}
    });

    // Let's emulate dynamic value update
    setTimeout(function () {
        c4.circleProgress('value', 0.7);
    }, 1000);
    setTimeout(function () {
        c4.circleProgress('value', 1.0);
    }, 1100);
    setTimeout(function () {
        c4.circleProgress('value', 0.5);
    }, 2100);

    /*
     * Example 5:
     *
     * - image fill; image should be squared; it will be stretched to SxS size, where S - size of the widget
     * - fallback color fill (when image is not loaded)
     * - custom widget size (default is 100px)
     * - custom circle thickness (default is 1/14 of the size)
     * - reverse drawing mode
     * - custom animation start value
     * - usage of "data-" attributes
     */
    $('.fifth.circle').circleProgress({
        value: 0.7
        // all other config options were taken from "data-" attributes
        // options passed in config object have higher priority than "data-" attributes
        // "data-" attributes are taken into account only on init (not on update/redraw)
        // "data-fill" (and other object options) should be in valid JSON format
    });
})(jQuery);