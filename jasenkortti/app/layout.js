import "./globals.css";

export const metadata = {
  title: "Jäsenkortti",
  description: "Seuroille myytävä digitaalinen jäsenkortti — demo",
};

export default function RootLayout({ children }) {
  return (
    <html lang="fi">
      <body>{children}</body>
    </html>
  );
}
