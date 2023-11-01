import { Accordion, AccordionSummary, AccordionDetails } from "@mui/material";
import ExpandMoreIcon from "@mui/icons-material/ExpandMore";
import { styled } from "@mui/system";
import styles from "../styles/style.module.scss";

const customAccordionStyles = {
  fontFamily: styles.baseFontFamily,
  backgroundColor: styles.pageBackgroundColor,
  border: "none",
  boxShadow: "none",
};

const customAccordionSummaryStyles = {
  backgroundColor: styles.pageBackgroundColor,
  color: styles.baseFontColor,
  cursor: "pointer",
  width: "100%",
  "&:hover": {
    color: styles.baseFontHoverColor,
  },
};

const customAccordionDetailsStyles = {
  backgroundColor: styles.pageBackgroundColor,
  color: styles.baseFontColor,
};

const customExpandMoreIconStyles = {
  color: styles.baseFontColor,
  "&:hover": {
    color: styles.baseFontHoverColor,
  },
};

const CustomAccordion = styled(Accordion)(customAccordionStyles);

const CustomAccordionSummary = styled(AccordionSummary)(
  customAccordionSummaryStyles
);

const CustomAccordionDetails = styled(AccordionDetails)(
  customAccordionDetailsStyles
);

const CustomExpandMoreIcon = styled(ExpandMoreIcon)(customExpandMoreIconStyles);

export {
  CustomAccordion,
  CustomAccordionSummary,
  CustomAccordionDetails,
  CustomExpandMoreIcon,
};
