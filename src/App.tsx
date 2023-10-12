import "./styles/main.scss";
import Header from "./components/Header";
import Main from "./components/Main";
import Footer from "./components/Footer";
import GoogleTag from "./components/GoogleTag";
import GoogleAnalytics from "./components/GoogleAnalytics";

function App() {
  return (
    <div className="App">
      <GoogleTag trackingId="G-5S3XNVK5V5" />
      <Header />
      <Main />
      <Footer />
      <GoogleAnalytics measurementId="G-5S3XNVK5V5" />
    </div>
  );
}

export default App;
