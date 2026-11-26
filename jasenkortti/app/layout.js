import "./globals.css";

export const metadata = {
  title: "EpSu-kortti",
  description: "Seuroille myytävä EpSu-kortti — demo",
};

export default function RootLayout({ children }) {
  return (
    <html lang="fi">
      <body>{children}</body>
    </html>
  );
}
