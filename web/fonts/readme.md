## Fonts

For optimal performance and browser compatibility include font files in both .otf and .woff2 formats

1. OpenType (.otf) is the succesor to TrueType (.ttf) developed by Microsoft and Adobe. Optimal for general use
2. Web Open Font Format 2 (.woff2) recommended format for delivering web fonts by W3C

#### How to include fonts correctly

Try to avoid using a cdn for fonts if possible. Storing the font files on the server gives us faster loading and less chance for needing fallback fonts because of cdn downtime.

```scss
@font-face {
	font-family: "CustomFont";
	font-weight: 400;
	font-style: normal;
	src: url("/fonts/customfont.woff2") format("woff2"), url("/fonts/customfont.otf") format("opentype");
}
```

#### Detailed article

https://creativemarket.com/blog/the-missing-guide-to-font-formats
