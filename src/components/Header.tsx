// import logo from "../assets/deprogrammer.svg";
import logo from "../assets/tdp_logo_blue.svg";
import QRCode from "react-qr-code";
import { graphqlUrl } from "../data/graphqlConfig";

const Header = () => {
  return (
    <header className="header">
      <div>
        <a href={graphqlUrl} target="_blank" rel="noreferrer">
          <img
            className="logo"
            src={logo}
            alt="Deprogrammer"
            title="Deprogrammer"
          />
        </a>
      </div>
      {/* <div className='tagline'>We're taking you out of the rabbit hole.</div> */}
      <div className="qr-code">
        <QRCode value={graphqlUrl} size={100} />
      </div>
    </header>
  );
};

export default Header;
